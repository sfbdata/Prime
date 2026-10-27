<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/**
 * Leitor das configurações globais do Master (spec `login-config-cadastro.md`). Os outros domínios dependem só
 * deste contrato, nunca das entranhas do Master.
 *
 * Nunca derruba quem lê: o valor gravado, senão o padrão do catálogo; qualquer falha de leitura e chave desconhecida
 * também caem no padrão (chave desconhecida, no texto vazio). Só lê, nunca grava.
 */
interface ConfiguracaoDaPlataforma
{
    public function valor(string $chave): string;
}
