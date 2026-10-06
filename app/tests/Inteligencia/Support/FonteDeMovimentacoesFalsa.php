<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Support;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\FonteDeMovimentacoesDoPush;
use App\Processo\Entity\MovimentacaoProcesso;

/**
 * Fonte canned para os testes de unidade do montador/UseCase: devolve o que o teste colocar nas
 * listas públicas, ignorando números/ids (o recorte por tenant é provado no teste funcional da
 * implementação Doctrine).
 */
final class FonteDeMovimentacoesFalsa implements FonteDeMovimentacoesDoPush
{
    /** @var list<PublicacaoDjen> */
    public array $publicacoes = [];

    /** @var list<MovimentacaoProcesso> */
    public array $movimentacoes = [];

    /** @var list<string> */
    public array $equipe = [];

    public function publicacoesDoTenant(Tenant $tenant, array $numeros, int $limite): array
    {
        return array_slice($this->publicacoes, 0, $limite);
    }

    public function movimentacoesDoTenant(Tenant $tenant, array $processoIds, int $limite): array
    {
        return array_slice($this->movimentacoes, 0, $limite);
    }

    public function chavesDoTenant(Tenant $tenant, array $numeros, array $processoIds, int $limitePublicacoes, int $limiteMovimentacoes): array
    {
        $chaves = [];
        foreach ($this->publicacoesDoTenant($tenant, $numeros, $limitePublicacoes) as $p) {
            $chaves[] = 'pub:' . (int) $p->getId();
        }
        foreach ($this->movimentacoesDoTenant($tenant, $processoIds, $limiteMovimentacoes) as $m) {
            $chaves[] = 'mov:' . (int) $m->getId();
        }

        return $chaves;
    }

    public function nomesDaEquipe(Tenant $tenant): array
    {
        return $this->equipe;
    }
}
