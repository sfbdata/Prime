<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\DTO\EditarPastaDTO;
use App\Pasta\Service\AndamentoDaPasta;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Editar dados" da pasta. Com o autor, o que mudou vira o cartão do sistema do desenho 02 (`peVals`, dc L4473):
 * "Dados da pasta: dados editados: número 12 → 13; cliente "Ana" → "Ana Maria".". Só entram os campos que a tela já
 * mostra (número, cliente e ação); sem mudança, nada é gravado. A tela manda a situação pelo caso de uso da pílula;
 * se a situação chegar diferente por aqui, ela sai como a da pílula: o cartão "Situação da pasta" e, ao arquivar, o
 * andamento a Finalizada (sem isso a pasta arquivada por aqui não contava em "Pastas concluídas" do Dashboard).
 */
final class EditarPastaUseCase
{
    private const VAZIO = 'Não informado';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?RegistroDoSistemaNaPasta $registro = null,
        private readonly ?AndamentoDaPasta $andamento = null,
    ) {}

    public function executar(EditarPastaDTO $dto, Pasta $pasta, ?User $autor = null): void
    {
        $nup = trim($dto->nup);

        if ($nup === '') {
            throw new \InvalidArgumentException('O NUP é obrigatório.');
        }

        $antes = [$pasta->getNup(), $pasta->getNomeCliente(), $pasta->getNomeAcao()];

        // NUP pode repetir (sync Drive): a identidade é o driveFolderId, não o NUP.
        $pasta->setNup($nup);
        $pasta->setNomeCliente($dto->nomeCliente);
        $pasta->setNomeAcao($dto->nomeAcao);

        // As 4 situações do desenho: aceitar só Ativo/Arquivado fazia o modal (que não tinha Suspenso nem Cancelado)
        // mandar "ativo" e reativar a pasta suspensa sem ninguém pedir.
        if (in_array($dto->situacao, Pasta::SITUACOES, true) && $dto->situacao !== $pasta->getSituacao()) {
            $this->mudarSituacao($pasta, $dto->situacao, $autor);
        }

        $mudou = self::mudancas($antes, [$pasta->getNup(), $pasta->getNomeCliente(), $pasta->getNomeAcao()]);
        if ($mudou !== [] && $autor !== null && $this->registro !== null) {
            $this->registro->registrar($pasta, $autor, 'pasta', 'dados editados: ' . implode('; ', $mudou) . '.');
        }

        $this->em->flush();
    }

    /** O mesmo que a pílula faz ({@see DefinirSituacaoDaPastaUseCase}), sem o flush (quem dá é o executar). */
    private function mudarSituacao(Pasta $pasta, string $situacao, ?User $autor): void
    {
        $de = $pasta->getSituacaoRotulo();
        $pasta->setSituacao($situacao);
        if ($autor === null) {
            return;
        }
        $this->registro?->registrar($pasta, $autor, 'situacao', 'alterada de "' . $de . '" para "' . $pasta->getSituacaoRotulo() . '".');
        $tenant = $pasta->getTenant();
        if ($situacao === Pasta::SITUACAO_ARQUIVADA && $tenant !== null) {
            $this->andamento?->finalizarPorArquivamento($pasta, $autor, $tenant);
        }
    }

    /**
     * Como o `mudou` do desenho: "número X → Y" sem aspas e os textos entre aspas.
     *
     * @param array{0: ?string, 1: ?string, 2: ?string} $antes
     * @param array{0: ?string, 1: ?string, 2: ?string} $depois
     *
     * @return list<string>
     */
    private static function mudancas(array $antes, array $depois): array
    {
        [$a, $d] = [array_map(self::normal(...), $antes), array_map(self::normal(...), $depois)];
        $mudou = [];
        if ($a[0] !== $d[0]) {
            $mudou[] = 'número ' . ($a[0] ?? self::VAZIO) . ' → ' . ($d[0] ?? self::VAZIO);
        }
        if ($a[1] !== $d[1]) {
            $mudou[] = 'cliente "' . ($a[1] ?? self::VAZIO) . '" → "' . ($d[1] ?? self::VAZIO) . '"';
        }
        if ($a[2] !== $d[2]) {
            $mudou[] = 'ação "' . ($a[2] ?? self::VAZIO) . '" → "' . ($d[2] ?? self::VAZIO) . '"';
        }

        return $mudou;
    }

    private static function normal(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
