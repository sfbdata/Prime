// @ts-check
// Regressão visual: fotografa telas e compara com as referências aprovadas pelo dono.
//
//   npm run test:visual              compara com as referências (falha mostra antes/depois/diferença)
//   npm run test:visual:aprovar      grava as fotos atuais como as novas referências
//   npm run test:visual:relatorio    abre o relatório com as imagens lado a lado
//
// As referências ficam em tests/visual/__referencias__/ e entram no git: aprovar uma tela é
// commitar a foto dela. Roda contra o ambiente de DEV (localhost:8080), com o usuário de E2E.
// Procedimento completo: docs/regressao-visual.md.
const { defineConfig, devices } = require('@playwright/test');
const base = require('./playwright.config.js');

module.exports = defineConfig({
    ...base,
    testDir: './tests',
    testMatch: ['**/auth.setup.js', '**/visual/**/*.spec.js'],
    reporter: [['list'], ['html', { outputFolder: 'relatorio-visual', open: 'never' }]],
    snapshotPathTemplate: '{testDir}/visual/__referencias__/{projectName}/{arg}{ext}',
    expect: {
        toHaveScreenshot: {
            // Até 0,5% dos pixels pode variar (antisserrilhado de fonte entre execuções).
            maxDiffPixelRatio: 0.005,
            animations: 'disabled',
            caret: 'hide',
            scale: 'css',
        },
    },
    use: {
        ...base.use,
        colorScheme: 'light',
        timezoneId: 'America/Sao_Paulo',
    },
    projects: [
        { name: 'setup', testMatch: '**/auth.setup.js' },
        {
            name: 'desktop',
            testMatch: '**/visual/**/*.spec.js',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 }, storageState: 'storage/auth.json' },
            dependencies: ['setup'],
        },
        {
            name: 'celular',
            testMatch: '**/visual/**/*.spec.js',
            use: { ...devices['Pixel 7'], storageState: 'storage/auth.json' },
            dependencies: ['setup'],
        },
    ],
});
