<?php

declare(strict_types=1);

namespace App\Produto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Produto\Entity\Contratacao;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Repository\ContratacaoRepository;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Repository\LiberacaoDoVotoRepository;

/**
 * O que o escritório novo ganha quando nasce de um cadastro com produto (spec multiproduto, §5, item 3).
 *
 * Grava a linha de contratação (origem `cadastro`) e, no Voto, a `LiberacaoDoVoto` já liberada. Não liga a I.A. nem
 * outro produto. Só persiste: quem chama está dentro da transação do cadastro e dá o `flush()`, então tudo grava junto
 * com a conta e o escritório, ou nada grava.
 */
final class ContratacaoDoCadastro
{
    public function __construct(
        private readonly ContratacaoRepository $contratacoes,
        private readonly LiberacaoDoVotoRepository $liberacoesDoVoto,
    ) {
    }

    public function contratar(Tenant $tenant, User $dono, Produto $produto, \DateTimeImmutable $agora): void
    {
        $this->contratacoes->salvar(Contratacao::ativar($tenant, $produto, OrigemDaContratacao::Cadastro, $dono, $agora));
        if (Produto::Voto === $produto) {
            $liberacao = new LiberacaoDoVoto($tenant);
            $liberacao->liberar();
            $this->liberacoesDoVoto->salvar($liberacao);
        }
    }
}
