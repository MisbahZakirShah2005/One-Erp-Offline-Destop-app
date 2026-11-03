const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const root = path.resolve(__dirname, '..');
const required = [
  'runtime/php/php.exe',
  'runtime/php/php.ini',
  'runtime/php/ext/php_zip.dll',
  'runtime/mysql/bin/mysqld.exe',
  'runtime/mysql/bin/mysql.exe',
  'runtime/mysql/bin/mysqladmin.exe',
  'data/saas_pos.sql',
  'build/VC_redist.x64.exe',
  'build/VC_redist.x86.exe'
];

let failed = false;
console.log('\nONEERP runtime verification\n');

for (const rel of required) {
  const full = path.join(root, rel);

  if (!fs.existsSync(full)) {
    failed = true;
    console.error('MISSING:', rel);
  } else {
    console.log('OK     :', rel);
  }
}

const phpExe = path.join(root, 'runtime', 'php', 'php.exe');
const phpIni = path.join(root, 'runtime', 'php', 'php.ini');
const extDir = path.join(root, 'runtime', 'php', 'ext').replace(/\\/g, '/');

if (fs.existsSync(phpExe) && fs.existsSync(phpIni)) {
  const r = spawnSync(phpExe, [
    '-c', phpIni,
    '-d', `extension_dir=${extDir}`,
    '-d', 'extension=php_zip.dll',
    '-r', 'echo "ONEERP_BUILD_PHP_OK";'
  ], {
    cwd: path.dirname(phpExe),
    windowsHide: true,
    encoding: 'utf8',
    env: {
      ...process.env,
      PATH: path.dirname(phpExe) + path.delimiter + (process.env.PATH || '')
    },
    timeout: 12000
  });

  if (r.error || r.status !== 0 || !String(r.stdout || '').includes('ONEERP_BUILD_PHP_OK')) {
    failed = true;
    console.error('\nPHP PREFLIGHT FAILED');
    if (r.error) console.error(r.error.message);
    if (r.stderr) console.error(r.stderr);
  } else {
    console.log('\nOK     : bundled PHP preflight');
  }
}

if (failed) {
  console.error('\nBUILD BLOCKED: runtime/prerequisite incomplete or broken.');
  process.exit(1);
}

console.log('\nONEERP runtime verification passed.\n');
