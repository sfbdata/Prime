<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Repository\TermoDeVoluntariadoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tira um currículo do banco: as marcações de todos (sem FK, tabela polimórfica), o registro e, só
 * depois do COMMIT, os arquivos (INV-6). As candidaturas saem pela FK (CASCADE) e as marcações delas
 * aqui, antes. Os termos de voluntariado saem com o currículo (FK CASCADE, spec do termo): aqui eles são removidos
 * pelo ORM, antes, para o servidor registrar a exclusão na auditoria (sem CPF nem RG: o snapshot fica fora do diff).
 */
final class RemovedorDeCurriculo
{
    public function __construct(
        private readonly CurriculoRepository $repositorio,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
        private readonly CandidaturaRepository $candidaturas,
        private readonly TermoDeVoluntariadoRepository $termos,
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
            $this->marcacoes->removerDosItens(TipoItemMarcado::Candidatura, $this->candidaturas->idsDoCurriculo($cv));
            foreach ($this->termos->doCurriculo($cv) as $termo) {
                $this->termos->remover($termo);
            }
            $this->repositorio->remover($cv);
        });

        $this->remocao->remover($chaves, $contexto);
    }
}
