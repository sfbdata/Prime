// @ts-check
const { defineConfig, devices } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

// Carrega e2e/.env (sem dependência externa). Não sobrescreve vars já definidas.
(function carregarDotEnv() {
    const arquivo = path.join(__dirname, '.env');
    if (!fs.existsSync(arquivo)) return;
    for (const linha of fs.readFileSync(arquivo, 'utf8').split('\n')) {
        const texto = linha.trim();
        if (!texto || texto.startsWith('#')) continue;
        const i = texto.indexOf('=');
        if (i === -1) continue;
        const chave = texto.slice(0, i).trim();
        if (process.env[chave] === undefined) {
            process.env[chave] = texto.slice(i + 1).trim();
        }
    }
})();

module.exports = defineConfig({
    testDir: './tests',
    timeout: 60000,
    retries: 0,
    reporter: 'list',
    use: {
        baseURL: 'http://localhost:8080',
        headless: true,
        locale: 'pt-BR',
        reducedMotion: 'reduce',
    },
    projects: [
        {
            name: 'setup',
            testMatch: '**/auth.setup.js',
        },
        {
            name: 'chromium',
            testIgnore: '**/central-fidelidade.spec.js',
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'storage/auth.json',
            },
            dependencies: ['setup'],
        },
        // Central × protótipo do Designer: login próprio e servidor da worktree (ver o cabeçalho do spec). Só entra
        // quando as credenciais vêm no comando, para o `npm test` comum não depender da demo de pé.
        ...(process.env.CENTRAL_E2E_EMAIL ? [{
            name: 'central-fidelidade',
            testMatch: '**/central-fidelidade.spec.js',
            use: { ...devices['Desktop Chrome'] },
        }] : []),
    ],
});
