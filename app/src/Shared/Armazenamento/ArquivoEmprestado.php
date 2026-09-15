<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

/**
 * Um caminho local que **não é nosso** — referência emprestada (D9).
 *
 * É o que o backend local devolve quando alguém pede um caminho real para ler: como o arquivo já
 * está em disco, o caminho entregue é **o do próprio arquivo de produção**, em cópia zero.
 *
 * ## Por que isto é um tipo, e não um `bool $ownsFile`
 *
 * Com flag, apagar o arquivo de produção exigiria apenas esquecer um `if`. Com tipo, a classe
 * **não tem** `liberar()` e **não tem** `__destruct()`: não existe a linha de código que apagaria.
 * Quem precisa de algo descartável pede {@see ArquivoTemporarioPossuido}, que é outra classe.
 *
 * ## Por que não há interface comum entre os dois
 *
 * Uma `ArquivoMaterializado` compartilhada deixaria uma função aceitar qualquer um dos dois e
 * voltar a decidir em runtime se apaga — exatamente o que D9 proíbe. Quem quiser mesmo aceitar os
 * dois precisa escrever a união na assinatura, e isso é visível na revisão.
 *
 * ## O risco concreto que isto evita
 *
 * `BinaryFileResponse` só abre o arquivo em `sendContent()`
 * (`vendor/symfony/http-foundation/BinaryFileResponse.php:321`), **depois** que o controller
 * retornou, e apaga o arquivo se `deleteFileAfterSend` estiver ligado (`:332-334`). Um
 * materializado com destrutor que apagasse, entregue ao download, removeria o documento do
 * cliente do disco depois de servi-lo. É o INV-9.
 */
final readonly class ArquivoEmprestado
{
    public function __construct(
        private string $caminho,
    ) {
    }

    /**
     * Caminho local para LEITURA. Escrever aqui altera o arquivo persistido — quem precisa
     * escrever pede uma cópia gravável.
     */
    public function caminho(): string
    {
        return $this->caminho;
    }
}
