<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// No ParaTest, cada processo ganha banco, uploads, logs e temporários próprios. Nas duas execuções,
// garante que os diretórios de upload do processo existam.
App\Tests\Shared\Paralelo\ProcessoDeTesteParalelo::aplicar(dirname(__DIR__));

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
