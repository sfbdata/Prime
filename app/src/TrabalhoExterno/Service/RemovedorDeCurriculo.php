<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tira um currículo do banco: as marcações de todos (sem FK, tabela polimórfica), o registro e, só
 * depois do COMMIT, os arquivos (INV-6). As candidaturas entram na F2b. O Termo de Voluntariado
 * aceito, quando existir (F3b), fica com o usuário (coordenador 19/10).
 */
final class RemovedorDeCurriculo
{
    public function __construct(
        private readonly CurriculoRepository $repositorio,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
        private readonly RemocaoAposTransacao $remocao,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function remover(Curriculo $cv, string $contexto): void
    {
        $chaves = array_map(ChavesDeTrabalhoExterno::arquivoDoCurriculoPorNome(...), $cv->chavesDosArquivos());

        // As marcações (DELETE em DQL) e o currículo saem juntos ou não saem: uma transação só.
        $this->em->wrapInTransaction(function () use ($cv): void {
            $this->marcacoes->removerDoItem(TipoItemMarcado::Curriculo, (int) $cv->getId());
            $this->repositorio->remover($cv);
        });

        $this->remocao->remover($chaves, $contexto);
    }
}
