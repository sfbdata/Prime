<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Shared\Contract\ConfiguracaoDaPlataforma;
use Psr\Log\LoggerInterface;

/**
 * Leitor das configurações globais (spec `login-config-cadastro.md`): o valor gravado na ConfiguracaoGlobal, senão o
 * padrão do {@see CatalogoDeConfiguracoes}. Qualquer Throwable (banco, tabela ausente) e chave fora do catálogo caem
 * no padrão, sem lançar. Sem cache próprio: o repositório já guarda a linha só dentro da requisição, então a mudança do
 * Master vale na requisição seguinte.
 */
final class LeitorDeConfiguracaoDaPlataforma implements ConfiguracaoDaPlataforma
{
    public function __construct(
        private readonly ConfiguracaoGlobalRepository $repositorio,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function valor(string $chave): string
    {
        $padroes = CatalogoDeConfiguracoes::padroes();
        if (!array_key_exists($chave, $padroes)) {
            return '';
        }

        $gravado = $this->gravados()[$chave] ?? null;

        return is_string($gravado) && $gravado !== '' ? $gravado : $padroes[$chave];
    }

    /** @return array<string, string> */
    private function gravados(): array
    {
        try {
            // `atual()`: leitura de uma linha, cacheada pelo repositório só dentro da requisição.
            return $this->repositorio->atual()?->getValores() ?? [];
        } catch (\Throwable $e) {
            $this->logger?->warning('Configurações globais: falha na leitura; valem os padrões.', ['exception' => $e]);

            return [];
        }
    }
}
