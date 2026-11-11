const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');
const frontendDir = path.join(rootDir, 'frontend');

const files = fs.readdirSync(frontendDir).filter(f => f.endsWith('.html'));

console.log('--- Verifying Resource References in HTML Pages ---');

let totalChecked = 0;
let errors = 0;

for (const file of files) {
    const htmlPath = path.join(frontendDir, file);
    const content = fs.readFileSync(htmlPath, 'utf8');

    // Check stylesheets
    const cssMatches = content.matchAll(/href=["']([^"']+\.css)["']/g);
    for (const match of cssMatches) {
        totalChecked++;
        let cssRef = match[1];
        if (cssRef.startsWith('/')) {
            cssRef = cssRef.substring(1);
        }
        const fullCssPath = path.join(rootDir, cssRef);
        if (!fs.existsSync(fullCssPath)) {
            console.error(`[ERROR] In ${file}: CSS file not found: ${match[1]} (resolved: ${fullCssPath})`);
            errors++;
        }
    }

    // Check scripts
    const jsMatches = content.matchAll(/src=["']([^"']+\.js)["']/g);
    for (const match of jsMatches) {
        totalChecked++;
        let jsRef = match[1];
        if (jsRef.startsWith('/')) {
            jsRef = jsRef.substring(1);
        }
        const fullJsPath = path.join(rootDir, jsRef);
        if (!fs.existsSync(fullJsPath)) {
            console.error(`[ERROR] In ${file}: JS file not found: ${match[1]} (resolved: ${fullJsPath})`);
            errors++;
        }
    }

    // Check internal links
    const linkMatches = content.matchAll(/href=["'](\/frontend\/[^"']+\.html)["']/g);
    for (const match of linkMatches) {
        totalChecked++;
        let linkRef = match[1];
        if (linkRef.startsWith('/')) {
            linkRef = linkRef.substring(1);
        }
        const fullLinkPath = path.join(rootDir, linkRef);
        if (!fs.existsSync(fullLinkPath)) {
            console.error(`[ERROR] In ${file}: HTML link target not found: ${match[1]} (resolved: ${fullLinkPath})`);
            errors++;
        }
    }
}

console.log(`Checked ${totalChecked} references across ${files.length} HTML files. Errors found: ${errors}`);
if (errors > 0) {
    process.exit(1);
} else {
    console.log('[ALL ASSET & HTML REFERENCES 100% VALID]');
}

