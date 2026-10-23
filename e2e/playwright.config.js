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
            // A regressão visual tem configuração própria (playwright.visual.config.js).
            testIgnore: ['**/central-fidelidade.spec.js', '**/ponto-atalho-cabecalho.spec.js', '**/fechar-janelas.spec.js', '**/visual/**'],
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'storage/auth.json',
            },
            dependencies: ['setup'],
        },
        // Central × protótipo do Designer: login próprio e servidor da worktree (ver o cabeçalho do spec). Só entra
        // quando as credenciais vêm no comando, para o `npm test` comum não depender da demo de pé.
        // Atalho de ponto do cabeçalho (spec presenca-e-ponto-no-cabecalho §3): login próprio e servidor da worktree.
        // Só entra com as credenciais no comando; as rotas do ponto são interceptadas (nada é gravado).
        ...(process.env.PONTO_ATALHO_E2E_EMAIL ? [{
            name: 'ponto-atalho',
            testMatch: '**/ponto-atalho-cabecalho.spec.js',
            use: { ...devices['Desktop Chrome'] },
        }] : []),
        // O X de fechar em toda janelinha: login próprio e servidor da worktree; só com as credenciais no comando.
        ...(process.env.FECHAR_E2E_EMAIL ? [{
            name: 'fechar-janelas',
            testMatch: '**/fechar-janelas.spec.js',
            use: { ...devices['Desktop Chrome'] },
        }] : []),
        ...(process.env.CENTRAL_E2E_EMAIL ? [{
            name: 'central-fidelidade',
            testMatch: '**/central-fidelidade.spec.js',
            use: { ...devices['Desktop Chrome'] },
        }] : []),
    ],
});
