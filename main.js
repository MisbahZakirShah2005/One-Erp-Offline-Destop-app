const { app, BrowserWindow, dialog } = require('electron');
const { spawn } = require('child_process');
const path = require('path');
const fs = require('fs');
const net = require('net');

let phpServer = null;
let mysqlServer = null;

const PHP_PORT = 18181;
const MYSQL_PORT = 3307;

// IMPORTANT:
// Ye database name aap ki local offline database ka naam hai.
// Agar aap ki SQL file me database name different hai to yahan bhi same naam rakho.
const DB_NAME = 'saas_pos';

function rootPath() {
    return app.isPackaged ? process.resourcesPath : __dirname;
}

function phpExePath() {
    return path.join(rootPath(), 'runtime', 'php', 'php.exe');
}

function phpIniPath() {
    return path.join(rootPath(), 'runtime', 'php', 'php.ini');
}

function mysqlServerExePath() {
    return path.join(rootPath(), 'runtime', 'mysql', 'bin', 'mysqld.exe');
}

function mysqlClientExePath() {
    return path.join(rootPath(), 'runtime', 'mysql', 'bin', 'mysql.exe');
}

function appFolderPath() {
    return path.join(rootPath(), 'app');
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

function copyFolderSync(source, target) {
    if (!fs.existsSync(source)) return;

    if (!fs.existsSync(target)) {
        fs.mkdirSync(target, { recursive: true });
    }

    for (const item of fs.readdirSync(source)) {
        const sourcePath = path.join(source, item);
        const targetPath = path.join(target, item);

        if (fs.statSync(sourcePath).isDirectory()) {
            copyFolderSync(sourcePath, targetPath);
        } else {
            fs.copyFileSync(sourcePath, targetPath);
        }
    }
}

function prepareMysqlData() {
    const targetData = mysqlRuntimeDataPath();
    const sourceData = mysqlTemplateDataPath();

    if (!fs.existsSync(targetData) || fs.readdirSync(targetData).length === 0) {
        copyFolderSync(sourceData, targetData);
    }
}

function waitForPort(port, timeout = 30000) {
    return new Promise((resolve, reject) => {
        const start = Date.now();

        function check() {
            const socket = new net.Socket();
            socket.setTimeout(1000);

            socket.on('connect', () => {
                socket.destroy();
                resolve(true);
            });

            socket.on('timeout', () => {
                socket.destroy();
                retry();
            });

            socket.on('error', () => {
                retry();
            });

            socket.connect(port, '127.0.0.1');
        }

        function retry() {
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

        let errorOutput = '';

        child.stderr.on('data', (data) => {
            errorOutput += data.toString();
        });

        child.on('close', (code) => {
            if (code === 0) {
                resolve(true);
            } else {
                reject(new Error(errorOutput || `Command failed with code ${code}`));
            }
        });

        child.on('error', reject);
    });
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

        let errorOutput = '';
        let normalOutput = '';
        let isClosed = false;
        let rejected = false;

        function safeReject(err) {
            if (!rejected) {
                rejected = true;
                reject(err);
            }
        }

        child.stdout.on('data', (data) => {
            normalOutput += data.toString();
        });

        child.stderr.on('data', (data) => {
            errorOutput += data.toString();
        });

        child.stdin.on('error', (err) => {
            // EPIPE ka matlab mysql.exe pehle hi close ho gaya.
            // Is ko crash na banne do; actual MySQL error child.stderr me aayega.
            if (err.code !== 'EPIPE') {
                errorOutput += '\nSTDIN Error: ' + err.message;
            }
        });

        child.on('error', (err) => {
            isClosed = true;
            safeReject(err);
        });

        child.on('close', (code) => {
            isClosed = true;

            if (code === 0) {
                resolve(true);
            } else {
                safeReject(new Error(
                    errorOutput ||
                    normalOutput ||
                    `SQL import failed with code ${code}`
                ));
            }
        });

        const sqlStream = fs.createReadStream(sqlPath);

        sqlStream.on('error', (err) => {
            if (!isClosed) {
                try { child.stdin.end(); } catch (e) {}
            }
            safeReject(err);
        });

        sqlStream.on('data', (chunk) => {
            if (!isClosed && child.stdin.writable) {
                const canWrite = child.stdin.write(chunk);

                if (!canWrite) {
                    sqlStream.pause();
                    child.stdin.once('drain', () => {
                        sqlStream.resume();
                    });
                }
            }
        });

        sqlStream.on('end', () => {
            if (!isClosed && child.stdin.writable) {
                child.stdin.end();
            }
        });
    });
}

async function importDatabaseIfNeeded() {
    const markerFile = path.join(mysqlRuntimeDataPath(), '.onerp_db_imported');
    const sqlPath = sqlFilePath();
    const mysqlExe = mysqlClientExePath();

    if (fs.existsSync(markerFile)) {
        return;
    }

    if (!fs.existsSync(sqlPath)) {
        throw new Error(`Database SQL file nahi mili:\n${sqlPath}`);
    }
await runCommand(mysqlExe, [
    '-h', '127.0.0.1',
    '-P', String(MYSQL_PORT),
    '-u', 'root',
    '-e',
    `DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
]);

    await importSqlFile(mysqlExe, sqlPath);

    fs.writeFileSync(markerFile, 'imported');
}
function startMysql() {
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

    mysqlServer = spawn(mysqlExe, [
        `--datadir=${mysqlData}`,
        `--port=${MYSQL_PORT}`,
        '--bind-address=127.0.0.1',
        '--console'
    ], {
        windowsHide: true
    });

    mysqlServer.on('error', (err) => {
        dialog.showErrorBox('MySQL Error', err.message);
    });

    return true;
}

function startPhp() {
    const phpExe = phpExePath();
    const appPath = appFolderPath();

    if (!fs.existsSync(phpExe)) {
        dialog.showErrorBox('PHP Missing', `php.exe nahi mila:\n${phpExe}`);
        return false;
    }

    if (!fs.existsSync(appPath)) {
        dialog.showErrorBox('App Missing', `app folder nahi mila:\n${appPath}`);
        return false;
    }
phpServer = spawn(phpExe, [
    '-c',
    phpIniPath(),
    '-S',
    `127.0.0.1:${PHP_PORT}`,
    '-t',
    appPath
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

    phpServer.on('error', (err) => {
        dialog.showErrorBox('PHP Server Error', err.message);
    });

    return true;
}

async function createWindow() {
    prepareMysqlData();

    if (!startMysql()) {
        app.quit();
        return;
    }

    try {
        await waitForPort(MYSQL_PORT, 30000);
        await importDatabaseIfNeeded();
    } catch (error) {
        dialog.showErrorBox('Database Error', error.message);
        app.quit();
        return;
    }

    if (!startPhp()) {
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

    const win = new BrowserWindow({
        width: 1400,
        height: 900,
        minWidth: 1100,
        minHeight: 700,
        title: 'ONEERP Offline',
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true
        }
    });

    win.setMenu(null);

    // Offline local URL
    win.loadURL(`http://127.0.0.1:${PHP_PORT}/public/app/views/login_view.php`);
}

app.whenReady().then(createWindow);

app.on('window-all-closed', () => {
    if (phpServer) phpServer.kill();
    if (mysqlServer) mysqlServer.kill();

    if (process.platform !== 'darwin') {
        app.quit();
    }
});