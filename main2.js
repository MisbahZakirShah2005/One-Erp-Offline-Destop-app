const { app, BrowserWindow, dialog, session } = require('electron');
const { spawn, spawnSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const net = require('net');

let phpServer = null;
let mysqlServer = null;
let mainWindow = null;

const PHP_PORT = 18181;
const MYSQL_PORT = 3307;
const DB_NAME = 'saas_pos';

const gotLock = app.requestSingleInstanceLock();

if (!gotLock) {
    app.quit();
} else {
    app.on('second-instance', () => {
        if (mainWindow) {
            if (mainWindow.isMinimized()) mainWindow.restore();
            mainWindow.focus();
        }
    });
}

function rootPath() {
    return app.isPackaged ? process.resourcesPath : __dirname;
}

function phpExePath() {
    return path.join(rootPath(), 'runtime', 'php', 'php.exe');
}

function phpIniPath() {
    return path.join(rootPath(), 'runtime', 'php', 'php.ini');
}

function phpExtensionDirPath() {
    return path.join(rootPath(), 'runtime', 'php', 'ext');
}

function phpZipDllPath() {
    return path.join(phpExtensionDirPath(), 'php_zip.dll');
}

function mysqlServerExePath() {
    return path.join(rootPath(), 'runtime', 'mysql', 'bin', 'mysqld.exe');
}

function mysqlClientExePath() {
    return path.join(rootPath(), 'runtime', 'mysql', 'bin', 'mysql.exe');
}

function mysqlAdminExePath() {
    return path.join(rootPath(), 'runtime', 'mysql', 'bin', 'mysqladmin.exe');
}

function mysqlDumpExePath() {
    return path.join(rootPath(), 'runtime', 'mysql', 'bin', 'mysqldump.exe');
}


function mysqlInstallDbExePath() {
    const binDir = path.join(
        rootPath(),
        'runtime',
        'mysql',
        'bin'
    );

    const candidates = [
        'mysql_install_db.exe',
        'mariadb-install-db.exe'
    ];

    for (const fileName of candidates) {
        const fullPath = path.join(binDir, fileName);

        if (fs.existsSync(fullPath)) {
            return fullPath;
        }
    }

    return null;
}
function webRootPath() {
    /*
     * DEV:
     *   rootPath() = C:\xampp\htdocs\onerp
     *   web root   = C:\xampp\htdocs
     *
     * PACKAGED:
     *   web root = %APPDATA%\ONEERP Offline\webroot
     *   actual writable app = webroot\onerp\app
     *
     * This keeps the same URL/filesystem layout that the current
     * offline ONEERP updater already uses:
     *   /onerp/app/config/...
     *   /onerp/app/app/...
     *   /onerp/app/public/...
     */
    if (app.isPackaged) {
        return path.join(app.getPath('userData'), 'webroot');
    }

    return path.dirname(rootPath());
}

function appFolderPath() {
    if (app.isPackaged) {
        return path.join(webRootPath(), 'onerp', 'app');
    }

    return path.join(rootPath(), 'app');
}

function appSeedPath() {
    return path.join(rootPath(), 'app');
}

function prepareWritableApp() {
    if (!app.isPackaged) return;

    const target = appFolderPath();
    const seed = appSeedPath();

    if (!fs.existsSync(seed)) {
        throw new Error(`Packaged ONEERP app seed nahi mila:\n${seed}`);
    }

    if (!fs.existsSync(target) || fs.readdirSync(target).length === 0) {
        copyFolderSync(seed, target);
    }
}

function mysqlTemplateDataPath() {
    return path.join(rootPath(), 'runtime', 'mysql', 'data');
}

function mysqlRuntimeDataPath() {
    if (app.isPackaged) {
        return path.join(app.getPath('userData'), 'mysql-data');
    }

    return path.join(rootPath(), 'data', 'mysql-data');
}

function sqlFilePath() {
    return path.join(rootPath(), 'data', 'saas_pos.sql');
}

function importMarkerPath() {
    return path.join(mysqlRuntimeDataPath(), '.onerp_db_imported');
}

function copyFolderSync(source, target, options = {}) {
    if (!fs.existsSync(source)) return;

    const skipTopLevel = new Set(
        (options.skipTopLevel || []).map(v => String(v).toLowerCase())
    );
    const rootSource = options.rootSource || source;

    if (!fs.existsSync(target)) {
        fs.mkdirSync(target, { recursive: true });
    }

    for (const item of fs.readdirSync(source)) {
        if (
            source === rootSource &&
            skipTopLevel.has(String(item).toLowerCase())
        ) {
            continue;
        }

        const sourcePath = path.join(source, item);
        const targetPath = path.join(target, item);

        if (fs.statSync(sourcePath).isDirectory()) {
            copyFolderSync(sourcePath, targetPath, {
                ...options,
                rootSource
            });
        } else {
            fs.copyFileSync(sourcePath, targetPath);
        }
    }
}

async function prepareMysqlData() {
    const targetData = mysqlRuntimeDataPath();

    const mysqlSystemDir = path.join(targetData, 'mysql');
    const ibdataFile = path.join(targetData, 'ibdata1');

    /*
    |--------------------------------------------------------------------------
    | EXISTING VALID DATABASE
    |--------------------------------------------------------------------------
    | Existing client ka DB bilkul touch nahi hoga.
    |--------------------------------------------------------------------------
    */
    if (
        fs.existsSync(targetData) &&
        fs.existsSync(mysqlSystemDir) &&
        fs.existsSync(ibdataFile)
    ) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | FAILED / INCOMPLETE FIRST INSTALL BACKUP
    |--------------------------------------------------------------------------
    */
    if (fs.existsSync(targetData)) {
        let hasFiles = false;

        try {
            hasFiles = fs.readdirSync(targetData).length > 0;
        } catch (_) {}

        if (hasFiles) {
            const stamp = new Date()
                .toISOString()
                .replace(/[:.]/g, '-');

            const backupPath = path.join(
                path.dirname(targetData),
                `mysql-data-broken-${stamp}`
            );

            try {
                fs.renameSync(targetData, backupPath);
            } catch (error) {
                throw new Error(
                    'Incomplete MySQL data folder backup nahi hua:\n' +
                    targetData +
                    '\n\n' +
                    error.message
                );
            }
        } else {
            try {
                fs.rmSync(targetData, {
                    recursive: true,
                    force: true
                });
            } catch (_) {}
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE CLEAN DATA DIRECTORY
    |--------------------------------------------------------------------------
    */
    fs.mkdirSync(targetData, {
        recursive: true
    });

    /*
    |--------------------------------------------------------------------------
    | MYSQL / MARIADB INITIALIZER
    |--------------------------------------------------------------------------
    */
    const installer = mysqlInstallDbExePath();

    if (!installer) {
        throw new Error(
            'MariaDB initialization utility nahi mili.\n\n' +
            'Expected:\n' +
            'runtime\\mysql\\bin\\mysql_install_db.exe'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FIRST-TIME DATABASE INITIALIZATION
    |--------------------------------------------------------------------------
    | Ye wahi command automatically chal rahi hai jo affected client PC par
    | manually successful hui.
    |--------------------------------------------------------------------------
    */
    try {
        await runCommand(installer, [
            `--datadir=${targetData}`,
            '--password='
        ]);
    } catch (error) {
        throw new Error(
            'MariaDB first-time initialization failed.\n\n' +
            error.message
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE INITIALIZATION
    |--------------------------------------------------------------------------
    */
    if (
        !fs.existsSync(path.join(targetData, 'mysql')) ||
        !fs.existsSync(path.join(targetData, 'ibdata1'))
    ) {
        throw new Error(
            'MariaDB initialization complete nahi hui.\n\n' +
            'Data path:\n' +
            targetData
        );
    }
}

function isPortOpen(port, host = '127.0.0.1') {
    return new Promise((resolve) => {
        const socket = new net.Socket();

        const done = (value) => {
            try { socket.destroy(); } catch (_) {}
            resolve(value);
        };

        socket.setTimeout(700);
        socket.once('connect', () => done(true));
        socket.once('timeout', () => done(false));
        socket.once('error', () => done(false));
        socket.connect(port, host);
    });
}

function waitForPort(port, timeout = 30000) {
    return new Promise((resolve, reject) => {
        const start = Date.now();

        async function check() {
            if (await isPortOpen(port)) {
                resolve(true);
                return;
            }

            if (Date.now() - start > timeout) {
                reject(new Error(`Port ${port} start nahi hua.`));
                return;
            }

            setTimeout(check, 500);
        }

        check();
    });
}

function runCommand(command, args) {
    return new Promise((resolve, reject) => {
        const child = spawn(command, args, {
            windowsHide: true
        });

        let stdout = '';
        let stderr = '';

        child.stdout?.on('data', (data) => {
            stdout += data.toString();
        });

        child.stderr?.on('data', (data) => {
            stderr += data.toString();
        });

        child.on('close', (code) => {
            if (code === 0) {
                resolve(stdout);
            } else {
                reject(new Error(stderr || stdout || `Command failed with code ${code}`));
            }
        });

        child.on('error', reject);
    });
}


function desktopDataRootPath() {
    if (app.isPackaged) {
        return path.join(app.getPath('userData'), 'desktop-data');
    }

    return path.join(rootPath(), 'data');
}

function forceReseedFlagPath() {
    if (app.isPackaged) {
        return path.join(
            desktopDataRootPath(),
            'FORCE_DESKTOP_DB_RESEED_ONCE'
        );
    }

    return path.join(
        rootPath(),
        'data',
        'FORCE_DESKTOP_DB_RESEED_ONCE'
    );
}

function routeFixMarkerPath() {
    return path.join(desktopDataRootPath(), '.desktop_route_fix_v6_done');
}

function isTextAppFile(file) {
    const ext = path.extname(file).toLowerCase();
    return ['.php', '.js', '.html', '.htm'].includes(ext);
}

function shouldSkipRouteRepair(relativePath) {
    const rel = String(relativePath).replace(/\\/g, '/').toLowerCase();

    return (
        rel.startsWith('storage/') ||
        rel.startsWith('helpers/updater/') ||
        rel.includes('/storage/') ||
        rel.endsWith('/repair_offline_103_paths.php') ||
        rel.endsWith('/repair_offline_103_runtime_paths.php')
    );
}

function rewriteDesktopAbsolutePaths(content) {
    /*
     * V6: idempotent path rewrite.
     *
     * - Collapse duplicate prefixes made by older patches.
     * - Rewrite ONLY quoted root-absolute legacy paths.
     * - Already-correct /onerp/app/... paths remain unchanged.
     */

    let out = content;

    while (out.includes('/onerp/app/onerp/app/')) {
        out = out.replaceAll('/onerp/app/onerp/app/', '/onerp/app/');
    }

    while (out.includes('/onerp/app/onerp/')) {
        out = out.replaceAll('/onerp/app/onerp/', '/onerp/');
    }

    out = out
        .replace(/(['"])\/public\//g, '$1/onerp/app/public/')
        .replace(/(['"])\/app\//g, '$1/onerp/app/app/')
        .replace(/(['"])\/config\//g, '$1/onerp/app/config/');

    return out;
}

function repairDesktopAbsoluteRoutesOnce() {
    const marker = routeFixMarkerPath();
    if (fs.existsSync(marker)) {
        return;
    }

    const appRoot = appFolderPath();
    const dataRoot = desktopDataRootPath();
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    const backupRoot = path.join(dataRoot, 'desktop_route_backups', stamp);

    const changed = [];

    function walk(folder) {
        for (const name of fs.readdirSync(folder)) {
            const full = path.join(folder, name);
            const stat = fs.statSync(full);

            if (stat.isDirectory()) {
                walk(full);
                continue;
            }

            if (!isTextAppFile(full)) {
                continue;
            }

            const relative = path.relative(appRoot, full);
            if (shouldSkipRouteRepair(relative)) {
                continue;
            }

            const before = fs.readFileSync(full, 'utf8');
            const after = rewriteDesktopAbsolutePaths(before);

            if (after === before) {
                continue;
            }

            const backup = path.join(backupRoot, relative);
            fs.mkdirSync(path.dirname(backup), { recursive: true });
            fs.copyFileSync(full, backup);
            fs.writeFileSync(full, after, 'utf8');

            changed.push(relative);
        }
    }

    fs.mkdirSync(dataRoot, { recursive: true });
    walk(appRoot);

    fs.writeFileSync(
        marker,
        JSON.stringify({
            fixed_at: new Date().toISOString(),
            changed_files: changed
        }, null, 2),
        'utf8'
    );
}

function runDumpToFile(command, args, outputFile) {
    return new Promise((resolve, reject) => {
        fs.mkdirSync(path.dirname(outputFile), { recursive: true });

        const out = fs.createWriteStream(outputFile);
        let stderr = '';

        const child = spawn(command, args, {
            windowsHide: true,
            stdio: ['ignore', 'pipe', 'pipe']
        });

        child.stdout.pipe(out);

        child.stderr.on('data', data => {
            stderr += data.toString();
        });

        child.on('error', err => {
            try { out.end(); } catch (_) {}
            reject(err);
        });

        child.on('close', code => {
            out.end();

            if (code === 0) {
                resolve(true);
            } else {
                reject(new Error(
                    stderr || `Database backup failed with code ${code}`
                ));
            }
        });
    });
}

async function databaseExists() {
    const out = await mysqlQuery(
        `SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB_NAME}';`
    );

    return (parseInt(String(out).trim(), 10) || 0) > 0;
}

async function reseedDesktopDatabaseOnceIfRequested() {
    const flag = forceReseedFlagPath();

    if (!fs.existsSync(flag)) {
        return;
    }

    const sqlPath = sqlFilePath();
    const mysqlExe = mysqlClientExePath();
    const dumpExe = mysqlDumpExePath();

    if (!fs.existsSync(sqlPath)) {
        throw new Error(
            `Desktop DB re-seed requested but current SQL file nahi mili:\n${sqlPath}`
        );
    }

    const sqlHead = fs.readFileSync(sqlPath, 'utf8').slice(0, 2_000_000);
    if (
        !sqlHead.includes('CREATE TABLE') &&
        !sqlHead.includes('INSERT INTO')
    ) {
        throw new Error(
            'data/saas_pos.sql valid database export nahi lag rahi. Re-seed stop kar diya.'
        );
    }

    if (await databaseExists()) {
        if (!fs.existsSync(dumpExe)) {
            throw new Error(
                `Safety backup ke liye mysqldump.exe nahi mila:\n${dumpExe}`
            );
        }

        const stamp = new Date().toISOString().replace(/[:.]/g, '-');
        const backupFile = path.join(
            desktopDataRootPath(),
            'desktop_db_backups',
            `saas_pos_before_reseed_${stamp}.sql`
        );

        await runDumpToFile(dumpExe, [
            '-h', '127.0.0.1',
            '-P', String(MYSQL_PORT),
            '-u', 'root',
            '--single-transaction',
            '--routines',
            '--events',
            '--triggers',
            '--default-character-set=utf8mb4',
            DB_NAME
        ], backupFile);
    }

    await runCommand(mysqlExe, [
        '-h', '127.0.0.1',
        '-P', String(MYSQL_PORT),
        '-u', 'root',
        '-e',
        `DROP DATABASE IF EXISTS \`${DB_NAME}\`;
         CREATE DATABASE \`${DB_NAME}\`
         DEFAULT CHARACTER SET utf8mb4
         COLLATE utf8mb4_unicode_ci;`
    ]);

    await importSqlFile(mysqlExe, sqlPath);

    fs.writeFileSync(
        importMarkerPath(),
        `reseeded ${new Date().toISOString()}`,
        'utf8'
    );

    fs.unlinkSync(flag);
}

async function mysqlQuery(sql) {
    const mysqlExe = mysqlClientExePath();

    if (!fs.existsSync(mysqlExe)) {
        throw new Error(`mysql.exe nahi mila:\n${mysqlExe}`);
    }

    return await runCommand(mysqlExe, [
        '-h', '127.0.0.1',
        '-P', String(MYSQL_PORT),
        '-u', 'root',
        '--default-character-set=utf8mb4',
        '-N',
        '-B',
        '-e',
        sql
    ]);
}

function importSqlFile(mysqlExe, sqlPath) {
    return new Promise((resolve, reject) => {
        const child = spawn(mysqlExe, [
            '-h', '127.0.0.1',
            '-P', String(MYSQL_PORT),
            '-u', 'root',
            '--default-character-set=utf8mb4',
            DB_NAME
        ], {
            windowsHide: true,
            stdio: ['pipe', 'pipe', 'pipe']
        });

        let stderr = '';
        let stdout = '';
        let closed = false;
        let settled = false;

        function fail(err) {
            if (!settled) {
                settled = true;
                reject(err);
            }
        }

        child.stdout.on('data', (data) => {
            stdout += data.toString();
        });

        child.stderr.on('data', (data) => {
            stderr += data.toString();
        });

        child.stdin.on('error', (err) => {
            if (err.code !== 'EPIPE') {
                stderr += '\nSTDIN Error: ' + err.message;
            }
        });

        child.on('error', (err) => {
            closed = true;
            fail(err);
        });

        child.on('close', (code) => {
            closed = true;

            if (settled) return;

            if (code === 0) {
                settled = true;
                resolve(true);
            } else {
                fail(new Error(stderr || stdout || `SQL import failed with code ${code}`));
            }
        });

        const stream = fs.createReadStream(sqlPath);

        stream.on('error', (err) => {
            if (!closed) {
                try { child.stdin.end(); } catch (_) {}
            }
            fail(err);
        });

        stream.on('data', (chunk) => {
            if (!closed && child.stdin.writable) {
                const writable = child.stdin.write(chunk);
                if (!writable) {
                    stream.pause();
                    child.stdin.once('drain', () => stream.resume());
                }
            }
        });

        stream.on('end', () => {
            if (!closed && child.stdin.writable) {
                child.stdin.end();
            }
        });
    });
}

async function ensureDatabaseReady() {
    const sqlPath = sqlFilePath();
    const mysqlExe = mysqlClientExePath();
    const markerFile = importMarkerPath();

    await mysqlQuery(
        `CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
    );

    const requiredTablesRaw = await mysqlQuery(
        `SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema='${DB_NAME}'
         AND table_name IN ('clients','users','items');`
    );

    const requiredTables = parseInt(String(requiredTablesRaw).trim(), 10) || 0;

    if (requiredTables === 3) {
        if (!fs.existsSync(markerFile)) {
            fs.writeFileSync(markerFile, `existing-db-detected ${new Date().toISOString()}`);
        }
        return;
    }

    const totalTablesRaw = await mysqlQuery(
        `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';`
    );

    const totalTables = parseInt(String(totalTablesRaw).trim(), 10) || 0;

    if (totalTables > 0) {
        throw new Error(
            `Local database partial/incomplete lag rahi hai (${totalTables} tables).\n` +
            `Safety ke liye ONEERP ne database delete nahi kiya.\n` +
            `Backup/repair karke dobara run karein.`
        );
    }

    if (!fs.existsSync(sqlPath)) {
        throw new Error(`Database SQL file nahi mili:\n${sqlPath}`);
    }

    await importSqlFile(mysqlExe, sqlPath);
    fs.writeFileSync(markerFile, `imported ${new Date().toISOString()}`);
}

async function startMysql() {
    if (await isPortOpen(MYSQL_PORT)) {
        return true;
    }

    const mysqlExe = mysqlServerExePath();
    const mysqlData = mysqlRuntimeDataPath();

    if (!fs.existsSync(mysqlExe)) {
        dialog.showErrorBox('MySQL Missing', `mysqld.exe nahi mila:\n${mysqlExe}`);
        return false;
    }

    if (!fs.existsSync(mysqlData)) {
        dialog.showErrorBox('MySQL Data Missing', `MySQL data folder nahi mila:\n${mysqlData}`);
        return false;
    }

const mysqlLogDir = path.join(
    app.getPath('userData'),
    'logs'
);

fs.mkdirSync(mysqlLogDir, {
    recursive: true
});

const mysqlLogFile = path.join(
    mysqlLogDir,
    'mysql-startup.log'
);

fs.appendFileSync(
    mysqlLogFile,
    '\n========================================\n' +
    `ONEERP MySQL Start: ${new Date().toISOString()}\n` +
    `Data: ${mysqlData}\n` +
    `Port: ${MYSQL_PORT}\n` +
    '========================================\n'
);

mysqlServer = spawn(mysqlExe, [
    `--datadir=${mysqlData}`,
    `--port=${MYSQL_PORT}`,
    '--bind-address=127.0.0.1',
    '--console'
], {
    windowsHide: true,
    cwd: path.dirname(mysqlExe)
});

mysqlServer.stdout?.on('data', (data) => {
    try {
        fs.appendFileSync(
            mysqlLogFile,
            data.toString()
        );
    } catch (_) {}
});

mysqlServer.stderr?.on('data', (data) => {
    try {
        fs.appendFileSync(
            mysqlLogFile,
            data.toString()
        );
    } catch (_) {}
});

mysqlServer.on('close', (code) => {
    try {
        fs.appendFileSync(
            mysqlLogFile,
            `\nMySQL process closed. Exit code: ${code}\n`
        );
    } catch (_) {}
});

mysqlServer.on('error', (err) => {
    try {
        fs.appendFileSync(
            mysqlLogFile,
            `\nSPAWN ERROR: ${err.message}\n`
        );
    } catch (_) {}

    dialog.showErrorBox(
        'MySQL Error',
        err.message
    );
});

    return true;
}

async function startPhp() {
    if (await isPortOpen(PHP_PORT)) {
        return true;
    }

    const phpExe = phpExePath();
    const appPath = appFolderPath();
    const webRoot = webRootPath();
    const phpExtDir = phpExtensionDirPath();
    const phpZipDll = phpZipDllPath();

    if (!fs.existsSync(phpExe)) {
        dialog.showErrorBox('PHP Missing', `php.exe nahi mila:\n${phpExe}`);
        return false;
    }

    if (!fs.existsSync(appPath)) {
        dialog.showErrorBox('App Missing', `app folder nahi mila:\n${appPath}`);
        return false;
    }

    if (!fs.existsSync(phpExtDir)) {
        dialog.showErrorBox(
            'PHP Extensions Missing',
            `PHP ext folder nahi mila:\n${phpExtDir}`
        );
        return false;
    }

    if (!fs.existsSync(phpZipDll)) {
        dialog.showErrorBox(
            'PHP ZIP Missing',
            'ONEERP updater ko PHP ZipArchive chahiye, lekin php_zip.dll bundled runtime mein nahi mila:\\n\\n' +
            phpZipDll +
            '\\n\\nSame PHP build/version ka php_zip.dll runtime/php/ext mein add karein.'
        );
        return false;
    }

    if (!fs.existsSync(webRoot)) {
        fs.mkdirSync(webRoot, { recursive: true });
    }

    /*
     * Explicitly load ZIP for the desktop PHP server.
     * This avoids relying on php.ini having extension=zip enabled.
     */
    const normalizedExtDir = phpExtDir.replace(/\\/g, '/');

    phpServer = spawn(phpExe, [
        '-c',
        phpIniPath(),
        '-d',
        `extension_dir=${normalizedExtDir}`,
        '-d',
        'extension=php_zip.dll',
        '-S',
        `127.0.0.1:${PHP_PORT}`,
        '-t',
        webRoot
    ], {
        windowsHide: true,
        cwd: path.dirname(phpExe),
        env: {
            ...process.env,
            ONEERP_OFFLINE: '1',
            ONEERP_DB_HOST: '127.0.0.1',
            ONEERP_DB_PORT: String(MYSQL_PORT),
            ONEERP_DB_NAME: DB_NAME,
            ONEERP_DB_USER: 'root',
            ONEERP_DB_PASS: ''
        }
    });

    phpServer.stderr.on('data', (data) => {
        console.error('[ONEERP PHP]', data.toString());
    });

    phpServer.on('error', (err) => {
        dialog.showErrorBox('PHP Server Error', err.message);
    });

    return true;
}

function stopLocalServices() {
    if (phpServer) {
        try { phpServer.kill(); } catch (_) {}
        phpServer = null;
    }

    const mysqlAdmin = mysqlAdminExePath();

    if (fs.existsSync(mysqlAdmin)) {
        try {
            spawnSync(mysqlAdmin, [
                '-h', '127.0.0.1',
                '-P', String(MYSQL_PORT),
                '-u', 'root',
                'shutdown'
            ], {
                windowsHide: true,
                timeout: 7000
            });
        } catch (_) {}
    }

    if (mysqlServer) {
        try { mysqlServer.kill(); } catch (_) {}
        mysqlServer = null;
    }
}


function installDesktopRouteGuard() {
    /*
    |--------------------------------------------------------------------------
    | ONEERP Desktop route compatibility guard V6
    |--------------------------------------------------------------------------
    | Local file:
    | C:\xampp\htdocs\onerp\app\app\auth\logout.php
    |
    | Correct URL:
    | /onerp/app/app/auth/logout.php
    |--------------------------------------------------------------------------
    */

    const filter = {
        urls: [
            `http://127.0.0.1:${PHP_PORT}/*`,
            `http://localhost:${PHP_PORT}/*`
        ]
    };

    session.defaultSession.webRequest.onBeforeRequest(
        filter,
        (details, callback) => {
            try {
                const url = new URL(details.url);
                let pathname = url.pathname || '';
                const originalPath = pathname;

                // Repair duplicated prefixes generated by older patches.
                while (pathname.includes('/onerp/app/onerp/app/')) {
                    pathname = pathname.replace(
                        '/onerp/app/onerp/app/',
                        '/onerp/app/'
                    );
                }

                while (pathname.includes('/onerp/app/onerp/')) {
                    pathname = pathname.replace(
                        '/onerp/app/onerp/',
                        '/onerp/'
                    );
                }

                // Prefix only truly bare legacy root paths.
                if (pathname.startsWith('/public/')) {
                    pathname = '/onerp/app' + pathname;
                } else if (pathname.startsWith('/app/')) {
                    pathname = '/onerp/app' + pathname;
                } else if (pathname.startsWith('/config/')) {
                    pathname = '/onerp/app' + pathname;
                }

                if (pathname === originalPath) {
                    callback({});
                    return;
                }

                url.pathname = pathname;

                callback({
                    redirectURL: url.toString()
                });
            } catch (error) {
                callback({});
            }
        }
    );
}

async function createWindow() {
    try {
        prepareWritableApp();
        repairDesktopAbsoluteRoutesOnce();
    } catch (error) {
        dialog.showErrorBox('ONEERP App Error', error.message);
        app.quit();
        return;
    }
try {
    await prepareMysqlData();
} catch (error) {
    dialog.showErrorBox(
        'ONEERP Database Initialization Error',
        error.message
    );

    app.quit();
    return;
}

if (!(await startMysql())) {
        app.quit();
        return;
    }

    try {
        await waitForPort(MYSQL_PORT, 30000);
        await reseedDesktopDatabaseOnceIfRequested();
        await ensureDatabaseReady();
    } catch (error) {
        dialog.showErrorBox('Database Error', error.message);
        app.quit();
        return;
    }

    if (!(await startPhp())) {
        app.quit();
        return;
    }

    try {
        await waitForPort(PHP_PORT, 15000);
    } catch (error) {
        dialog.showErrorBox('PHP Error', 'Local PHP server start nahi hua.');
        app.quit();
        return;
    }

    mainWindow = new BrowserWindow({
        width: 1400,
        height: 900,
        minWidth: 1100,
        minHeight: 700,
        title: 'ONEERP Offline',
        show: false,
        backgroundColor: '#070b16',
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true
        }
    });

    mainWindow.setMenu(null);

    mainWindow.once('ready-to-show', () => {
        mainWindow.show();
        mainWindow.focus();
    });

    mainWindow.on('closed', () => {
        mainWindow = null;
    });

    mainWindow.loadURL(
        `http://127.0.0.1:${PHP_PORT}/onerp/app/public/app/views/login_view.php`
    );
}

if (gotLock) {
    app.whenReady().then(async () => {
        installDesktopRouteGuard();
        await createWindow();
    });

    app.on('before-quit', () => {
        stopLocalServices();
    });

    app.on('window-all-closed', () => {
        if (process.platform !== 'darwin') {
            app.quit();
        }
    });
}
