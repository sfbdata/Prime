<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Excluir vaga" da Central (menu da l.1001): some para todos, com o histórico. As marcações dos
 * usuários não têm FK para a vaga (tabela polimórfica) e saem aqui. As candidaturas entram na F2b.
 */
final class ExcluirVagaUseCase
{
    public function __construct(
        private readonly LocalizadorDeVaga $localizador,
        private readonly PoliticaDaVaga $politica,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo): void
    {
        $vaga = $this->localizador->localizar($ctx, $codigo, new \DateTimeImmutable());
        if (!$this->politica->podeExcluir($ctx)) {
            throw new AcaoNaoPermitida('Só a Central exclui vagas.');
        }

        $this->marcacoes->removerDoItem(TipoItemMarcado::Vaga, (int) $vaga->getId());
        $this->em->remove($vaga);
        $this->em->flush();
    }
}
