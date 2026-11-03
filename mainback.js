const { app, BrowserWindow, dialog, session } = require('electron');
const { spawn, spawnSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const net = require('net');
const http = require('http');
const crypto = require('crypto');

let phpServer = null;
let mysqlServer = null;
let mainWindow = null;
let offlineSyncTimer = null;
let offlineSyncRunning = false;

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

function mysqlBaseDirPath() {
    return path.join(rootPath(), 'runtime', 'mysql');
}

function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function normalizeFsPathForCompare(value) {
    if (!value) return '';

    try {
        return path.resolve(String(value).trim())
            .replace(/[\\/]+$/, '')
            .toLowerCase();
    } catch (_) {
        return String(value).trim().replace(/[\\/]+$/, '').toLowerCase();
    }
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

    const criticalSeedFiles = [
        path.join('app', 'auth', 'login.php'),
        path.join('helpers', 'offline_prelogin_sync.php'),
        path.join('helpers', 'offline_sync_worker.php'),
        path.join('config', 'db.php')
    ];

    for (const relativeFile of criticalSeedFiles) {
        const fullPath = path.join(seed, relativeFile);
        if (!fs.existsSync(fullPath)) {
            throw new Error(
                'Packaged ONEERP seed incomplete hai. Missing file:\n' + fullPath
            );
        }
    }

    const dataRoot = desktopDataRootPath();
    const versionMarker = path.join(dataRoot, '.packaged_app_seed_version');
    const currentVersion = app.getVersion();

    let installedSeedVersion = '';
    try {
        installedSeedVersion = fs.existsSync(versionMarker)
            ? fs.readFileSync(versionMarker, 'utf8').trim()
            : '';
    } catch (_) {}

    const targetMissingOrEmpty =
        !fs.existsSync(target) || fs.readdirSync(target).length === 0;

    const mustRefreshSeed =
        targetMissingOrEmpty || installedSeedVersion !== currentVersion;

    if (!mustRefreshSeed) {
        return;
    }

    fs.mkdirSync(target, { recursive: true });
    copyFolderSync(seed, target);

    fs.mkdirSync(dataRoot, { recursive: true });
    fs.writeFileSync(versionMarker, currentVersion, 'utf8');
}

function compareVersionStrings(a, b) {
    const pa = String(a || '').split('.').map(v => parseInt(v, 10) || 0);
    const pb = String(b || '').split('.').map(v => parseInt(v, 10) || 0);
    const len = Math.max(pa.length, pb.length);

    for (let i = 0; i < len; i++) {
        const av = pa[i] || 0;
        const bv = pb[i] || 0;
        if (av > bv) return 1;
        if (av < bv) return -1;
    }

    return 0;
}

function syncUpdaterVersionFloor() {
    /*
     * The PHP updater keeps its own app_version.json. Older installed clients
     * can have a stale value (for example 1.0.6) even after a newer desktop
     * installer is installed. Treat the Electron build version as the minimum
     * installed version, but never downgrade a web-app update that is newer
     * than the Electron shell.
     */
    const desktopVersion = app.getVersion();
    const storageDir = path.join(appFolderPath(), 'storage');
    const versionFile = path.join(storageDir, 'app_version.json');

    let existingVersion = '';
    try {
        if (fs.existsSync(versionFile)) {
            const parsed = JSON.parse(fs.readFileSync(versionFile, 'utf8'));
            existingVersion = String(parsed?.version || '').trim();
        }
    } catch (_) {}

    if (
        existingVersion !== '' &&
        compareVersionStrings(existingVersion, desktopVersion) >= 0
    ) {
        return;
    }

    fs.mkdirSync(storageDir, { recursive: true });
    fs.writeFileSync(
        versionFile,
        JSON.stringify({
            version: desktopVersion,
            installed_at: new Date().toISOString(),
            source: 'desktop-installer-floor'
        }, null, 2),
        'utf8'
    );
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


function desktopSchemaPatchPath() {
    return path.join(
        rootPath(),
        'data',
        'migrations',
        'desktop_schema_1_0_9.sql'
    );
}

function desktopSchemaPatchMarkerPath() {
    return path.join(
        desktopDataRootPath(),
        '.desktop_schema_1_0_9_done'
    );
}

async function applyDesktopSchemaPatch109() {
    const marker = desktopSchemaPatchMarkerPath();

    if (fs.existsSync(marker)) {
        return;
    }

    const patchFile = desktopSchemaPatchPath();

    if (!fs.existsSync(patchFile)) {
        throw new Error(
            'Desktop schema patch 1.0.9 missing:\n' + patchFile
        );
    }

    await importSqlFile(mysqlClientExePath(), patchFile);

    fs.mkdirSync(desktopDataRootPath(), { recursive: true });
    fs.writeFileSync(
        marker,
        `applied ${new Date().toISOString()}`,
        'utf8'
    );
}

function desktopSchemaPatch110Path() {
    return path.join(
        rootPath(),
        'data',
        'migrations',
        'desktop_schema_1_0_10.sql'
    );
}

function desktopSchemaPatch110MarkerPath() {
    return path.join(
        desktopDataRootPath(),
        '.desktop_schema_1_0_10_done'
    );
}

async function applyDesktopSchemaPatch110() {
    const marker = desktopSchemaPatch110MarkerPath();

    if (fs.existsSync(marker)) {
        return;
    }

    const patchFile = desktopSchemaPatch110Path();

    if (!fs.existsSync(patchFile)) {
        throw new Error(
            'Desktop schema patch 1.0.10 missing:\n' + patchFile
        );
    }

    await importSqlFile(mysqlClientExePath(), patchFile);

    fs.mkdirSync(desktopDataRootPath(), { recursive: true });
    fs.writeFileSync(
        marker,
        `applied ${new Date().toISOString()}`,
        'utf8'
    );
}

function desktopSchemaPatch111Path() {
    return path.join(
        rootPath(),
        'data',
        'migrations',
        'desktop_schema_1_0_11.sql'
    );
}

function desktopSchemaPatch111MarkerPath() {
    return path.join(
        desktopDataRootPath(),
        '.desktop_schema_1_0_11_done'
    );
}

async function applyDesktopSchemaPatch111() {
    const marker = desktopSchemaPatch111MarkerPath();

    if (fs.existsSync(marker)) {
        return;
    }

    const patchFile = desktopSchemaPatch111Path();

    if (!fs.existsSync(patchFile)) {
        throw new Error(
            'Desktop schema patch 1.0.11 missing:\n' + patchFile
        );
    }

    await importSqlFile(mysqlClientExePath(), patchFile);

    fs.mkdirSync(desktopDataRootPath(), { recursive: true });
    fs.writeFileSync(
        marker,
        `applied ${new Date().toISOString()}`,
        'utf8'
    );
}


function desktopSchemaPatch112Path() {
    return path.join(
        rootPath(),
        'data',
        'migrations',
        'desktop_schema_1_0_12.sql'
    );
}

function desktopSchemaPatch112MarkerPath() {
    return path.join(
        desktopDataRootPath(),
        '.desktop_schema_1_0_12_done'
    );
}

async function applyDesktopSchemaPatch112() {
    const marker = desktopSchemaPatch112MarkerPath();

    if (fs.existsSync(marker)) {
        return;
    }

    const patchFile = desktopSchemaPatch112Path();

    if (!fs.existsSync(patchFile)) {
        throw new Error(
            'Desktop schema patch 1.0.12 missing:\n' + patchFile
        );
    }

    await importSqlFile(mysqlClientExePath(), patchFile);

    fs.mkdirSync(desktopDataRootPath(), { recursive: true });
    fs.writeFileSync(
        marker,
        `applied ${new Date().toISOString()}`,
        'utf8'
    );
}

function desktopSchemaPatch113Path() {
    return path.join(
        rootPath(),
        'data',
        'migrations',
        'desktop_schema_1_0_13.sql'
    );
}

function desktopSchemaPatch113MarkerPath() {
    return path.join(
        desktopDataRootPath(),
        '.desktop_schema_1_0_13_done'
    );
}

async function applyDesktopSchemaPatch113() {
    const marker = desktopSchemaPatch113MarkerPath();

    if (fs.existsSync(marker)) {
        return;
    }

    const patchFile = desktopSchemaPatch113Path();

    if (!fs.existsSync(patchFile)) {
        throw new Error(
            'Desktop schema patch 1.0.13 missing:\n' + patchFile
        );
    }

    await importSqlFile(mysqlClientExePath(), patchFile);

    fs.mkdirSync(desktopDataRootPath(), { recursive: true });
    fs.writeFileSync(
        marker,
        `applied ${new Date().toISOString()}`,
        'utf8'
    );
}

function desktopSchemaPatch115Path() {
    return path.join(
        rootPath(),
        'data',
        'migrations',
        'desktop_schema_1_0_15.sql'
    );
}

function desktopSchemaPatch115MarkerPath() {
    return path.join(
        desktopDataRootPath(),
        '.desktop_schema_1_0_15_done'
    );
}

async function applyDesktopSchemaPatch115() {
    const marker = desktopSchemaPatch115MarkerPath();

    if (fs.existsSync(marker)) {
        return;
    }

    const patchFile = desktopSchemaPatch115Path();

    if (!fs.existsSync(patchFile)) {
        throw new Error(
            'Desktop schema patch 1.0.15 missing:\n' + patchFile
        );
    }

    await importSqlFile(mysqlClientExePath(), patchFile);

    fs.mkdirSync(desktopDataRootPath(), { recursive: true });
    fs.writeFileSync(
        marker,
        `applied ${new Date().toISOString()}`,
        'utf8'
    );
}

function writeActiveDatabasePathMarker() {
    try {
        const dataRoot = desktopDataRootPath();
        fs.mkdirSync(dataRoot, { recursive: true });
        fs.writeFileSync(
            path.join(dataRoot, 'ACTIVE_DB_PATH.txt'),
            mysqlRuntimeDataPath(),
            'utf8'
        );
    } catch (_) {}
}

/*
|--------------------------------------------------------------------------
| Critical sync trigger integrity check
|--------------------------------------------------------------------------
| File markers alone are not trusted. Existing client DBs can survive across
| installer versions, so we verify the triggers inside the ACTIVE MariaDB DB.
| If any core trigger is missing, 1.0.11 is re-applied idempotently.
|--------------------------------------------------------------------------
*/
function criticalSyncTriggerNames() {
    return [
        'oneerp_bi_items_sync',
        'oneerp_ai_items_sync',
        'oneerp_bi_customers_sync',
        'oneerp_ai_customers_sync',
        'oneerp_bi_sales_sync',
        'oneerp_ai_sales_sync',
        'oneerp_bi_sale_items_sync',
        'oneerp_ai_sale_items_sync',
        'oneerp_bi_payments_sync',
        'oneerp_ai_payments_sync'
    ];
}

async function criticalSyncTriggerCount() {
    const names = criticalSyncTriggerNames();
    const quoted = names
        .map(name => "'" + String(name).replace(/'/g, "''") + "'")
        .join(',');

    const raw = await mysqlQuery(
        `SELECT COUNT(*)
         FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = '${DB_NAME}'
           AND TRIGGER_NAME IN (${quoted});`
    );

    return parseInt(String(raw).trim(), 10) || 0;
}

async function ensureCriticalSyncTriggers() {
    const expected = criticalSyncTriggerNames().length;
    let found = await criticalSyncTriggerCount();

    if (found === expected) {
        return;
    }

    const repairFile = desktopSchemaPatch111Path();

    if (!fs.existsSync(repairFile)) {
        throw new Error(
            'Sync trigger repair file missing:\n' + repairFile
        );
    }

    // 1.0.11 is idempotent: it drops/recreates sync triggers.
    await importSqlFile(mysqlClientExePath(), repairFile);

    found = await criticalSyncTriggerCount();

    if (found !== expected) {
        throw new Error(
            `ONEERP sync trigger repair incomplete. Expected ${expected}, found ${found}.`
        );
    }
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

    if (
        fs.existsSync(targetData) &&
        fs.existsSync(mysqlSystemDir) &&
        fs.statSync(mysqlSystemDir).isDirectory() &&
        fs.readdirSync(mysqlSystemDir).length > 0
    ) {
        return;
    }

    if (fs.existsSync(targetData)) {
        let hasFiles = false;

        try {
            hasFiles = fs.readdirSync(targetData).length > 0;
        } catch (_) {}

        if (hasFiles) {
            const stamp = new Date().toISOString().replace(/[:.]/g, '-');
            const backupPath = path.join(
                path.dirname(targetData),
                `mysql-data-broken-${stamp}`
            );

            try {
                fs.renameSync(targetData, backupPath);
            } catch (error) {
                throw new Error(
                    'Incomplete MySQL data folder backup nahi hua:\n' +
                    targetData + '\n\n' + error.message
                );
            }
        } else {
            try {
                fs.rmSync(targetData, { recursive: true, force: true });
            } catch (_) {}
        }
    }

    fs.mkdirSync(targetData, { recursive: true });

    const installer = mysqlInstallDbExePath();

    if (!installer) {
        throw new Error(
            'MariaDB initialization utility nahi mili.\n\n' +
            'Expected:\n' +
            'runtime\\mysql\\bin\\mysql_install_db.exe'
        );
    }

    const initLogDir = path.join(app.getPath('userData'), 'logs');
    const initLogFile = path.join(initLogDir, 'mysql-init.log');
    fs.mkdirSync(initLogDir, { recursive: true });

    try {
        /*
         * IMPORTANT:
         * MariaDB 10.4 Windows mysql_install_db.exe (bundled with ONEERP)
         * --no-defaults support nahi karta. Affected client PCs par exact
         * manual command below successfully tested hai, isliye initializer
         * ko sirf supported options ke saath run karna hai.
         */
        const output = await runCommand(installer, [
            `--datadir=${targetData}`,
            '--password='
        ]);

        fs.appendFileSync(
            initLogFile,
            `\n===== ${new Date().toISOString()} =====\n${output}\n`,
            'utf8'
        );
    } catch (error) {
        try {
            fs.appendFileSync(
                initLogFile,
                `\n===== ${new Date().toISOString()} INIT FAILED =====\n${error.message}\n`,
                'utf8'
            );
        } catch (_) {}

        throw new Error(
            'MariaDB first-time initialization failed.\n\n' +
            error.message +
            '\n\nLog:\n' + initLogFile
        );
    }

    const initializedSystemDir = path.join(targetData, 'mysql');

    if (
        !fs.existsSync(initializedSystemDir) ||
        !fs.statSync(initializedSystemDir).isDirectory() ||
        fs.readdirSync(initializedSystemDir).length === 0
    ) {
        throw new Error(
            'MariaDB initialization complete nahi hui.\n\n' +
            'System tables folder create nahi hua:\n' +
            initializedSystemDir +
            '\n\nLog:\n' + initLogFile
        );
    }

    await delay(1500);
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

function desktopDeviceUuidPath() {
    return path.join(desktopDataRootPath(), 'device_uuid.txt');
}

function desktopDeviceUuid() {
    const file = desktopDeviceUuidPath();

    try {
        if (fs.existsSync(file)) {
            const existing = fs.readFileSync(file, 'utf8').trim();

            if (/^desktop_[a-zA-Z0-9_-]{16,100}$/.test(existing)) {
                return existing;
            }
        }
    } catch (_) {}

    const generated = 'desktop_' + crypto.randomUUID().replace(/-/g, '');

    fs.mkdirSync(desktopDataRootPath(), { recursive: true });
    fs.writeFileSync(file, generated, 'utf8');

    return generated;
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
            '--no-defaults',
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
        '--no-defaults',
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
        '--no-defaults',
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
            '--no-defaults',
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
    const mysqlExe = mysqlServerExePath();
    const mysqlData = mysqlRuntimeDataPath();
    const mysqlBase = mysqlBaseDirPath();

    if (!fs.existsSync(mysqlExe)) {
        dialog.showErrorBox('MySQL Missing', `mysqld.exe nahi mila:\n${mysqlExe}`);
        return false;
    }

    if (!fs.existsSync(mysqlData)) {
        dialog.showErrorBox('MySQL Data Missing', `MySQL data folder nahi mila:\n${mysqlData}`);
        return false;
    }

    if (await isPortOpen(MYSQL_PORT)) {
        try {
            const runningDataDir = String(
                await mysqlQuery('SELECT @@datadir;')
            ).trim();

            if (
                normalizeFsPathForCompare(runningDataDir) ===
                normalizeFsPathForCompare(mysqlData)
            ) {
                return true;
            }
        } catch (_) {}

        dialog.showErrorBox(
            'MySQL Port Conflict',
            `Port ${MYSQL_PORT} kisi doosre MySQL/MariaDB process ke use mein hai.\n\n` +
            'ONEERP ne safety ke liye wrong database use nahi kiya.\n\n' +
            'Dusra MySQL/XAMPP instance band karke ONEERP dobara open karein.'
        );
        return false;
    }

    const mysqlLogDir = path.join(app.getPath('userData'), 'logs');
    fs.mkdirSync(mysqlLogDir, { recursive: true });
    const mysqlLogFile = path.join(mysqlLogDir, 'mysql-startup.log');

    for (let attempt = 1; attempt <= 3; attempt++) {
        fs.appendFileSync(
            mysqlLogFile,
            '\n========================================\n' +
            `ONEERP MySQL Start: ${new Date().toISOString()}\n` +
            `Attempt: ${attempt}/3\n` +
            `Base: ${mysqlBase}\n` +
            `Data: ${mysqlData}\n` +
            `Port: ${MYSQL_PORT}\n` +
            '========================================\n'
        );

        let processClosed = false;
        let processExitCode = null;

        mysqlServer = spawn(mysqlExe, [
            '--no-defaults',
            `--basedir=${mysqlBase}`,
            `--datadir=${mysqlData}`,
            `--port=${MYSQL_PORT}`,
            '--bind-address=127.0.0.1',
            '--console'
        ], {
            windowsHide: true,
            cwd: path.dirname(mysqlExe)
        });

        mysqlServer.stdout?.on('data', (data) => {
            try { fs.appendFileSync(mysqlLogFile, data.toString()); } catch (_) {}
        });

        mysqlServer.stderr?.on('data', (data) => {
            try { fs.appendFileSync(mysqlLogFile, data.toString()); } catch (_) {}
        });

        mysqlServer.on('error', (err) => {
            try {
                fs.appendFileSync(mysqlLogFile, `\nSPAWN ERROR: ${err.message}\n`);
            } catch (_) {}
        });

        mysqlServer.on('close', (code) => {
            processClosed = true;
            processExitCode = code;
            try {
                fs.appendFileSync(
                    mysqlLogFile,
                    `\nMySQL process closed. Exit code: ${code}\n`
                );
            } catch (_) {}
        });

        try {
            await waitForPort(MYSQL_PORT, 12000);

            const runningDataDir = String(
                await mysqlQuery('SELECT @@datadir;')
            ).trim();

            if (
                normalizeFsPathForCompare(runningDataDir) !==
                normalizeFsPathForCompare(mysqlData)
            ) {
                throw new Error(
                    'Port open hua lekin running MariaDB ONEERP data directory use nahi kar rahi.'
                );
            }

            return true;
        } catch (error) {
            try {
                fs.appendFileSync(
                    mysqlLogFile,
                    `\nAttempt ${attempt} failed: ${error.message}\n` +
                    `Process closed: ${processClosed}, exit code: ${processExitCode}\n`
                );
            } catch (_) {}

            if (mysqlServer && !processClosed) {
                try { mysqlServer.kill(); } catch (_) {}
            }
            mysqlServer = null;

            if (attempt < 3) {
                await delay(1500);
            }
        }
    }

    dialog.showErrorBox(
        'Database Error',
        `ONEERP bundled MariaDB port ${MYSQL_PORT} par start nahi hui.\n\n` +
        'Automatic initialization aur 3 startup attempts ho chuke hain.\n\n' +
        `Log file:\n${mysqlLogFile}`
    );

    return false;
}

function phpLogFilePath() {
    const logDir = path.join(app.getPath('userData'), 'logs');
    fs.mkdirSync(logDir, { recursive: true });
    return path.join(logDir, 'php-startup.log');
}

function appendPhpLog(message) {
    try {
        fs.appendFileSync(
            phpLogFilePath(),
            String(message || '') + (String(message || '').endsWith('\n') ? '' : '\n'),
            'utf8'
        );
    } catch (_) {}
}

function readPhpLogTail(maxChars = 7000) {
    try {
        const file = phpLogFilePath();
        if (!fs.existsSync(file)) return '';
        const data = fs.readFileSync(file, 'utf8');
        return data.slice(-maxChars);
    } catch (_) {
        return '';
    }
}

function phpHealthFilePath() {
    return path.join(webRootPath(), '.oneerp-php-health.txt');
}

function phpHealthToken() {
    return 'ONEERP_PHP_SERVER_OK_V1';
}

function preparePhpHealthFile() {
    const webRoot = webRootPath();
    fs.mkdirSync(webRoot, { recursive: true });

    fs.writeFileSync(
        phpHealthFilePath(),
        phpHealthToken(),
        'utf8'
    );
}

function httpGetLocal(pathname, timeout = 1200) {
    return new Promise((resolve) => {
        const req = http.get({
            host: '127.0.0.1',
            port: PHP_PORT,
            path: pathname,
            timeout
        }, (res) => {
            let body = '';

            res.setEncoding('utf8');

            res.on('data', chunk => {
                body += chunk;
                if (body.length > 10000) {
                    req.destroy();
                }
            });

            res.on('end', () => {
                resolve({
                    ok: res.statusCode >= 200 && res.statusCode < 300,
                    statusCode: res.statusCode || 0,
                    body
                });
            });
        });

        req.on('timeout', () => {
            try { req.destroy(); } catch (_) {}
            resolve({ ok: false, statusCode: 0, body: '' });
        });

        req.on('error', () => {
            resolve({ ok: false, statusCode: 0, body: '' });
        });
    });
}

async function isOneerpPhpServerReady() {
    const result = await httpGetLocal('/.oneerp-php-health.txt');

    return (
        result.ok &&
        String(result.body || '').trim() === phpHealthToken()
    );
}

function phpProcessEnvironment() {
    const phpDir = path.dirname(phpExePath());

    return {
        ...process.env,

        /*
         * Keep the bundled PHP folder first in PATH.
         * This helps Windows locate DLL dependencies used by php.exe
         * and PHP extensions on clean client PCs.
         */
        PATH: phpDir + path.delimiter + (process.env.PATH || ''),

        ONEERP_OFFLINE: '1',
        ONEERP_DB_HOST: '127.0.0.1',
        ONEERP_DB_PORT: String(MYSQL_PORT),
        ONEERP_DB_NAME: DB_NAME,
        ONEERP_DB_USER: 'root',
        ONEERP_DB_PASS: '',

        /*
         * Permanent updater DB-tool paths.
         * PHP updater/database_manager.php consumes these first, so installed
         * client PCs do not require XAMPP MySQL tools.
         */
        ONEERP_MYSQL_BIN: path.join(
            rootPath(),
            'runtime',
            'mysql',
            'bin'
        ),
        ONEERP_MYSQL_EXE: mysqlClientExePath(),
        ONEERP_MYSQLDUMP_EXE: mysqlDumpExePath(),
        ONEERP_MYSQLADMIN_EXE: mysqlAdminExePath(),

        ONEERP_RUNTIME_ROOT: rootPath(),
        ONEERP_APP_ROOT: appFolderPath(),
        ONEERP_DESKTOP_DATA_ROOT: desktopDataRootPath(),
        ONEERP_DESKTOP_VERSION: app.getVersion(),
        ONEERP_DEVICE_UUID: desktopDeviceUuid()
    };
}

function offlineSyncWorkerPath() {
    return path.join(appFolderPath(), 'helpers', 'offline_sync_worker.php');
}

function runOfflineSyncWorkerOnce() {
    if (offlineSyncRunning) {
        return Promise.resolve(false);
    }

    const worker = offlineSyncWorkerPath();

    if (!fs.existsSync(worker)) {
        appendPhpLog('[SYNC] Worker missing: ' + worker);
        return Promise.resolve(false);
    }

    offlineSyncRunning = true;

    return new Promise((resolve) => {
        const normalizedExtDir = phpExtensionDirPath().replace(/\\/g, '/');

        const child = spawn(phpExePath(), [
            '-c',
            phpIniPath(),
            '-d',
            `extension_dir=${normalizedExtDir}`,
            '-d',
            'extension=php_zip.dll',
            worker
        ], {
            windowsHide: true,
            cwd: path.dirname(phpExePath()),
            env: phpProcessEnvironment(),
            stdio: ['ignore', 'pipe', 'pipe']
        });

        let stdout = '';
        let stderr = '';

        child.stdout?.on('data', data => { stdout += data.toString(); });
        child.stderr?.on('data', data => { stderr += data.toString(); });

        child.on('error', (error) => {
            appendPhpLog('[SYNC ERROR] ' + error.message);
        });

        child.on('close', (code) => {
            offlineSyncRunning = false;

            if (stdout.trim()) {
                appendPhpLog('[SYNC] ' + stdout.trim());
            }

            if (stderr.trim()) {
                appendPhpLog('[SYNC STDERR] ' + stderr.trim());
            }

            if (code !== 0) {
                appendPhpLog('[SYNC] Worker exit code: ' + code);
            }

            resolve(code === 0);
        });
    });
}

function startOfflineSyncLoop() {
    if (offlineSyncTimer) {
        return;
    }

    runOfflineSyncWorkerOnce().catch(() => {});

    offlineSyncTimer = setInterval(() => {
        runOfflineSyncWorkerOnce().catch(() => {});
    }, 30000);
}

function runPhpPreflight() {
    const phpExe = phpExePath();
    const phpIni = phpIniPath();
    const phpExtDir = phpExtensionDirPath();
    const normalizedExtDir = phpExtDir.replace(/\\/g, '/');

    const args = [
        '-c',
        phpIni,
        '-d',
        `extension_dir=${normalizedExtDir}`,
        '-d',
        'extension=php_zip.dll',
        '-r',
        'echo "ONEERP_PHP_PREFLIGHT_OK";'
    ];

    appendPhpLog(
        '\n========================================\n' +
        `ONEERP PHP Preflight: ${new Date().toISOString()}\n` +
        `PHP: ${phpExe}\n` +
        `INI: ${phpIni}\n` +
        `EXT: ${phpExtDir}\n` +
        '========================================'
    );

    const result = spawnSync(phpExe, args, {
        windowsHide: true,
        cwd: path.dirname(phpExe),
        env: phpProcessEnvironment(),
        encoding: 'utf8',
        timeout: 12000
    });

    if (result.stdout) {
        appendPhpLog('[PREFLIGHT STDOUT]\n' + result.stdout);
    }

    if (result.stderr) {
        appendPhpLog('[PREFLIGHT STDERR]\n' + result.stderr);
    }

    if (result.error) {
        appendPhpLog('[PREFLIGHT ERROR]\n' + result.error.message);

        return {
            ok: false,
            error:
                'Bundled PHP execute nahi ho saka.\n\n' +
                result.error.message
        };
    }

    if (
        result.status !== 0 ||
        !String(result.stdout || '').includes('ONEERP_PHP_PREFLIGHT_OK')
    ) {
        return {
            ok: false,
            error:
                'Bundled PHP preflight fail hui.\n\n' +
                (String(result.stderr || '').trim() ||
                 String(result.stdout || '').trim() ||
                 `Exit code: ${result.status}`)
        };
    }

    return { ok: true };
}

async function waitForPhpServer(child, timeout = 18000) {
    const start = Date.now();

    while (Date.now() - start <= timeout) {
        if (await isOneerpPhpServerReady()) {
            return true;
        }

        if (
            !child ||
            child.exitCode !== null ||
            child.killed
        ) {
            throw new Error(
                'PHP process server ready hone se pehle band ho gaya.'
            );
        }

        await delay(300);
    }

    throw new Error(
        `PHP server ${PHP_PORT} par ${Math.round(timeout / 1000)} seconds mein ready nahi hua.`
    );
}

async function startPhp() {
    const phpExe = phpExePath();
    const phpIni = phpIniPath();
    const appPath = appFolderPath();
    const webRoot = webRootPath();
    const phpExtDir = phpExtensionDirPath();
    const phpZipDll = phpZipDllPath();
    const phpLogFile = phpLogFilePath();

    /*
     * Validate every file that the packaged PHP server actually requires.
     * This turns silent/generic startup failures into an exact diagnosis.
     */
    const requiredFiles = [
        ['PHP executable', phpExe],
        ['PHP configuration', phpIni],
        ['PHP ZIP extension', phpZipDll]
    ];

    for (const [label, file] of requiredFiles) {
        if (!fs.existsSync(file)) {
            appendPhpLog(`[MISSING] ${label}: ${file}`);

            dialog.showErrorBox(
                'ONEERP PHP Runtime Missing',
                `${label} nahi mila:\n${file}\n\n` +
                'Installer/runtime incomplete hai. ONEERP setup dobara install karein.'
            );

            return false;
        }
    }

    if (!fs.existsSync(appPath)) {
        dialog.showErrorBox(
            'ONEERP App Missing',
            `app folder nahi mila:\n${appPath}`
        );
        return false;
    }

    if (!fs.existsSync(phpExtDir)) {
        dialog.showErrorBox(
            'ONEERP PHP Extensions Missing',
            `PHP ext folder nahi mila:\n${phpExtDir}`
        );
        return false;
    }

    preparePhpHealthFile();

    /*
     * If the fixed port is already open, never blindly trust it.
     * Reuse it only when it is actually serving THIS ONEERP webroot.
     */
    if (await isPortOpen(PHP_PORT)) {
        if (await isOneerpPhpServerReady()) {
            appendPhpLog(
                `[REUSE] Existing ONEERP PHP server found on port ${PHP_PORT}.`
            );
            return true;
        }

        dialog.showErrorBox(
            'ONEERP PHP Port Conflict',
            `Port ${PHP_PORT} kisi doosre program ke use mein hai.\n\n` +
            'ONEERP ne safety ke liye wrong local server use nahi kiya.\n' +
            'Dusra program band karke ONEERP dobara open karein.'
        );

        return false;
    }

    /*
     * Run PHP once before starting the server.
     * Missing Visual C++ runtime / bad DLL / broken php.ini / broken zip
     * errors are caught here immediately.
     */
    const preflight = runPhpPreflight();

    if (!preflight.ok) {
        const tail = readPhpLogTail();

        dialog.showErrorBox(
            'ONEERP PHP Runtime Error',
            preflight.error +
            '\n\nDetailed log:\n' + phpLogFile +
            (tail ? '\n\nLast log output:\n' + tail.slice(-2500) : '')
        );

        return false;
    }

    const normalizedExtDir = phpExtDir.replace(/\\/g, '/');

    /*
     * Retry a few times for slow disks / endpoint-security scanning.
     * The server is considered started ONLY after an HTTP health check.
     */
    for (let attempt = 1; attempt <= 3; attempt++) {
        appendPhpLog(
            '\n========================================\n' +
            `ONEERP PHP Start: ${new Date().toISOString()}\n` +
            `Attempt: ${attempt}/3\n` +
            `PHP: ${phpExe}\n` +
            `INI: ${phpIni}\n` +
            `WEBROOT: ${webRoot}\n` +
            `PORT: ${PHP_PORT}\n` +
            '========================================'
        );

        let spawnError = null;

        phpServer = spawn(phpExe, [
            '-c',
            phpIni,
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
            env: phpProcessEnvironment(),
            stdio: ['ignore', 'pipe', 'pipe']
        });

        phpServer.stdout?.on('data', (data) => {
            appendPhpLog('[STDOUT] ' + data.toString());
        });

        phpServer.stderr?.on('data', (data) => {
            appendPhpLog('[STDERR] ' + data.toString());
        });

        phpServer.on('error', (err) => {
            spawnError = err;
            appendPhpLog('[SPAWN ERROR] ' + err.message);
        });

        phpServer.on('close', (code, signal) => {
            appendPhpLog(
                `[CLOSE] PHP process closed. code=${code}, signal=${signal || ''}`
            );
        });

        try {
            await waitForPhpServer(phpServer, 18000);

            appendPhpLog(
                `[READY] ONEERP PHP server healthy on 127.0.0.1:${PHP_PORT}`
            );

            return true;
        } catch (error) {
            appendPhpLog(
                `[ATTEMPT ${attempt} FAILED] ${error.message}` +
                (spawnError ? ` | spawn=${spawnError.message}` : '')
            );

            if (phpServer) {
                try { phpServer.kill(); } catch (_) {}
            }

            phpServer = null;

            if (attempt < 3) {
                await delay(1200);
            }
        }
    }

    const tail = readPhpLogTail();

    dialog.showErrorBox(
        'ONEERP PHP Startup Error',
        'Bundled PHP 3 attempts ke baad bhi start nahi hua.\n\n' +
        `Detailed log:\n${phpLogFile}` +
        (tail ? '\n\nLast log output:\n' + tail.slice(-3000) : '')
    );

    return false;
}

function stopLocalServices() {
    if (offlineSyncTimer) {
        clearInterval(offlineSyncTimer);
        offlineSyncTimer = null;
    }

    if (phpServer) {
        try { phpServer.kill(); } catch (_) {}
        phpServer = null;
    }

    const mysqlAdmin = mysqlAdminExePath();

    if (fs.existsSync(mysqlAdmin)) {
        try {
            spawnSync(mysqlAdmin, [
                '--no-defaults',
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
        syncUpdaterVersionFloor();
        repairDesktopAbsoluteRoutesOnce();
    } catch (error) {
        dialog.showErrorBox('ONEERP App Error', error.message);
        app.quit();
        return;
    }
try {
    await prepareMysqlData();
    writeActiveDatabasePathMarker();
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
        await applyDesktopSchemaPatch109();
        await applyDesktopSchemaPatch110();
        await applyDesktopSchemaPatch111();
        await applyDesktopSchemaPatch112();
        await applyDesktopSchemaPatch113();
        await applyDesktopSchemaPatch115();
        await ensureCriticalSyncTriggers();
    } catch (error) {
        dialog.showErrorBox('Database Error', error.message);
        app.quit();
        return;
    }

    if (!(await startPhp())) {
        app.quit();
        return;
    }

    startOfflineSyncLoop();

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
            contextIsolation: true,
            devTools: true
        }
    });

    mainWindow.setMenu(null);

    /*
    |--------------------------------------------------------------------------
    | PERMANENT DEVTOOLS SUPPORT
    |--------------------------------------------------------------------------
    | - Development (`npm start`): DevTools automatically open in a separate
    |   window after the page finishes loading.
    | - Development + packaged build: F12 or Ctrl+Shift+I toggles DevTools.
    | - Existing Electron / PHP / MySQL / updater workflow is untouched.
    |--------------------------------------------------------------------------
    */
    mainWindow.webContents.on('before-input-event', (event, input) => {
        const key = String(input.key || '').toLowerCase();

        const isF12 = key === 'f12';
        const isCtrlShiftI =
            input.control === true &&
            input.shift === true &&
            key === 'i';

        if (isF12 || isCtrlShiftI) {
            event.preventDefault();

            if (mainWindow.webContents.isDevToolsOpened()) {
                mainWindow.webContents.closeDevTools();
            } else {
                mainWindow.webContents.openDevTools({
                    mode: 'detach',
                    activate: true
                });
            }
        }
    });

    if (!app.isPackaged) {
        mainWindow.webContents.once('did-finish-load', () => {
            if (
                mainWindow &&
                !mainWindow.isDestroyed() &&
                !mainWindow.webContents.isDevToolsOpened()
            ) {
                mainWindow.webContents.openDevTools({
                    mode: 'detach',
                    activate: true
                });
            }
        });
    }


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
