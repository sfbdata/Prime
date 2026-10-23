<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// No ParaTest, cada processo ganha banco, uploads, logs e temporários próprios. Nas duas execuções,
// garante que os diretórios de upload do processo existam.
App\Tests\Shared\Paralelo\ProcessoDeTesteParalelo::aplicar(dirname(__DIR__));

// Os logs de teste só crescem: uma rodada completa grava uns 7 MB em cada um, mas nada os apagava e o
// test.log passou de 800 MB (o audit_test.log, de 1,5 GB). Passou do teto, começa vazio. Os testes que
// leem o log (PecaTextoArmazenamentoTest, CanalDeLogMcpTest) leem só o que escreveram, então zerar
// antes da rodada não os afeta; as últimas rodadas continuam no arquivo enquanto ele cabe no teto.
// O processo principal do ParaTest também inclui este bootstrap, mas sem PARATEST nem sufixo: ali os
// nomes são os da suíte serial, e zerar apagaria o log de outra rodada. Só os processos filhos limpam.
$processoPrincipalDoParatest = (string) getenv('PARATEST') === ''
    && str_contains(basename((string) ($_SERVER['argv'][0] ?? '')), 'paratest');
foreach ($processoPrincipalDoParatest ? [] : ['test', 'audit_test', 'mcp_test'] as $nomeDoLog) {
    $arquivoDeLog = dirname(__DIR__) . '/var/log/' . $nomeDoLog . (string) getenv('TESTE_PROCESSO') . '.log';
    if (is_file($arquivoDeLog) && filesize($arquivoDeLog) > 64 * 1024 * 1024) {
        @file_put_contents($arquivoDeLog, '');
    }
}

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
