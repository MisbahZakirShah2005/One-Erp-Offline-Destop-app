const fs = require('fs');
const path = require('path');
const https = require('https');

const root = path.resolve(__dirname, '..');
const buildDir = path.join(root, 'build');
const files = [
  ['VC_redist.x64.exe', 'https://aka.ms/vc14/vc_redist.x64.exe'],
  ['VC_redist.x86.exe', 'https://aka.ms/vc14/vc_redist.x86.exe']
];

fs.mkdirSync(buildDir, { recursive: true });

function download(url, dest, redirects = 0) {
  return new Promise((resolve, reject) => {
    if (redirects > 10) return reject(new Error('Too many redirects'));

    const req = https.get(url, {
      headers: { 'User-Agent': 'ONEERP-Build/1.0' }
    }, res => {
      const code = res.statusCode || 0;

      if ([301,302,303,307,308].includes(code)) {
        const loc = res.headers.location;
        res.resume();
        if (!loc) return reject(new Error('Redirect without location'));
        return download(new URL(loc, url).toString(), dest, redirects + 1)
          .then(resolve, reject);
      }

      if (code !== 200) {
        res.resume();
        return reject(new Error(`Download failed (${code}): ${url}`));
      }

      const tmp = dest + '.download';
      try { if (fs.existsSync(tmp)) fs.unlinkSync(tmp); } catch (_) {}

      const out = fs.createWriteStream(tmp);
      res.pipe(out);

      out.on('finish', () => out.close(() => {
        try {
          const size = fs.statSync(tmp).size;
          if (size < 1000000) {
            throw new Error(`Downloaded file too small: ${size} bytes`);
          }

          if (fs.existsSync(dest)) fs.unlinkSync(dest);
          fs.renameSync(tmp, dest);
          resolve();
        } catch (e) {
          reject(e);
        }
      }));

      out.on('error', reject);
    });

    req.setTimeout(120000, () => req.destroy(new Error('Download timeout')));
    req.on('error', reject);
  });
}

(async () => {
  console.log('\nONEERP: preparing Microsoft VC++ prerequisites...');

  for (const [name, url] of files) {
    const dest = path.join(buildDir, name);

    if (fs.existsSync(dest) && fs.statSync(dest).size >= 1000000) {
      console.log('OK     : ' + name);
      continue;
    }

    console.log('DOWNLOAD: ' + name);
    await download(url, dest);
    console.log('OK     : ' + name);
  }

  console.log('ONEERP: prerequisites ready.\n');
})().catch(err => {
  console.error('\nONEERP prerequisite download failed:\n' + err.message);
  console.error('\nBuild stopped so a broken installer is never created.');
  process.exit(1);
});
