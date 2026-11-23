<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AbonoEmLoteOutput;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Service\AbonoDeJustificativa;
use App\Ponto\Exception\AnaliseDeJustificativaRecusadaException;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\Service\TextoDoPonto;
use App\Repository\UserTenantRepository;

/**
 * "Abonar selecionadas" da aba Equipe da /ponto (spec `docs/specs/ponto-desenho-0811.md`, E5, P57; desenho 16 L217 e
 * L818): o gestor marca as justificativas da lista e abona todas de uma vez.
 *
 * Cada id passa pelas mesmas portas da ficha do colaborador:
 * - só do escritório da sessão e de quem tem vínculo ativo nele (id de outro escritório ou que não existe não abona);
 * - a própria só com o P43 a (`AnaliseDaPropriaJustificativa`: o primário e o Master);
 * - o abono em si é o da ficha (`AbonoDeJustificativa`), que ainda pode recusar (já analisada, batida já registrada,
 *   horário que não chegou).
 *
 * Como no desenho (L818), a própria de quem analisa é analisada uma a uma, com o evento dela na trilha; as da equipe
 * vão juntas, num abono só, e a trilha ganha UM evento "Abono em lote". Uma recusa não impede as outras, e as recusas
 * saem na ordem em que os ids vieram. Quem chama já conferiu que quem pede gere a equipe (`GestaoDoPonto::veEquipe`).
 */
final class AbonarJustificativasDaEquipeUseCase
{
    public const NAO_ENCONTRADA = 'Justificativa não encontrada neste escritório.';

    /** A recusa de cada pessoa: o nome, o dia e o motivo (o português de {@see self::comPessoaEDia()}). */
    public const RECUSA_DA_PESSOA = '%s (%s): %s';

    /** @var array<string, string> texto da constante => chave no catálogo `ponto_servidor` (spec idioma-do-sistema §33) */
    public const CHAVE_DO_TEXTO = [
        self::NAO_ENCONTRADA => 'ponto_servidor.recusa.abono.nao_encontrada',
        self::RECUSA_DA_PESSOA => 'ponto_servidor.recusa.abono.pessoa',
    ];

    public function __construct(
        private readonly JustificativaPontoRepository $justificativas,
        private readonly UserTenantRepository $vinculos,
        private readonly AnaliseDaPropriaJustificativa $analiseDaPropria,
        private readonly AbonoDeJustificativa $abono,
    ) {
    }

    /** @param list<int> $ids */
    public function executar(User $analista, Tenant $escritorio, array $ids): AbonoEmLoteOutput
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        $achadas = $this->justificativas->doEscritorioPorIds($ids, $escritorio);

        $recusas = [];
        $aAbonar = [];
        foreach ($ids as $id) {
            $recusa = $this->recusaDaPorta($achadas[$id] ?? null, $analista, $escritorio);
            if ($recusa === null) {
                $aAbonar[$id] = $achadas[$id];
            } else {
                $recusas[$id] = $recusa;
            }
        }
        $recusas += $this->abonar($aAbonar, $analista, $escritorio);

        $emOrdem = self::naOrdem($ids, $recusas);

        return new AbonoEmLoteOutput(
            \count($ids) - \count($recusas),
            array_map(static fn (TextoDoPonto $recusa): string => $recusa->portugues, $emOrdem),
            $emOrdem,
        );
    }

    /** Null quando passa pelas portas; senão, a recusa já com a pessoa e o dia. */
    private function recusaDaPorta(?JustificativaPonto $justificativa, User $analista, Tenant $escritorio): ?TextoDoPonto
    {
        $dono = $justificativa?->getUser();
        if ($justificativa === null || $dono === null || !$this->vinculos->existeVinculoAtivo($dono, $escritorio)) {
            return TextoDoPonto::de(self::CHAVE_DO_TEXTO[self::NAO_ENCONTRADA], self::NAO_ENCONTRADA);
        }

        return $this->analiseDaPropria->permite($analista, $dono, $escritorio)
            ? null
            : self::comPessoaEDia($justificativa, AnaliseDaPropriaJustificativa::RECUSA);
    }

    /**
     * A própria de quem analisa, uma a uma; as da equipe, num abono só (desenho L818).
     *
     * @param array<int, JustificativaPonto> $aAbonar pelo id, todas já pelas portas
     *
     * @return array<int, TextoDoPonto> as recusas do abono, pelo id, com a pessoa e o dia
     */
    private function abonar(array $aAbonar, User $analista, Tenant $escritorio): array
    {
        $recusas = [];
        $daEquipe = [];
        foreach ($aAbonar as $id => $justificativa) {
            $dono = $justificativa->getUser();
            if ($dono === null || !$this->analiseDaPropria->ehAPropria($analista, $dono)) {
                $daEquipe[$id] = $justificativa;
            } elseif (($recusa = $this->abono->abonar($justificativa, $escritorio, $analista)) !== null) {
                $recusas[$id] = $recusa;
            }
        }
        if ($daEquipe !== []) {
            // Só conta a recusa de uma que foi pedida: um id que não veio não vira recusa.
            $recusas += array_intersect_key($this->abono->abonarDaEquipe(array_values($daEquipe), $escritorio, $analista), $daEquipe);
        }

        $comPessoaEDia = [];
        foreach ($recusas as $id => $recusa) {
            $comPessoaEDia[$id] = self::comPessoaEDia($aAbonar[$id], $recusa);
        }

        return $comPessoaEDia;
    }

    /**
     * A recusa com a pessoa e o dia. O motivo chega como português (a recusa do abono da ficha viaja como texto) e volta
     * a ter chave quando é uma recusa conhecida da análise; outro texto entra como veio.
     */
    private static function comPessoaEDia(JustificativaPonto $justificativa, string $motivo): TextoDoPonto
    {
        $dono = $justificativa->getUser();

        return TextoDoPonto::de(self::CHAVE_DO_TEXTO[self::RECUSA_DA_PESSOA], self::RECUSA_DA_PESSOA, [
            'pessoa' => (string) ($dono?->getFullName() ?? $dono?->getEmail()),
            'dia' => (string) $justificativa->getData()?->format('d/m/Y'),
            'motivo' => AnaliseDeJustificativaRecusadaException::textoDaMensagem($motivo) ?? $motivo,
        ]);
    }

    /**
     * @param list<int>          $ids
     * @param array<int, TextoDoPonto> $recusas pelo id
     *
     * @return list<TextoDoPonto> na ordem em que os ids vieram
     */
    private static function naOrdem(array $ids, array $recusas): array
    {
        $emOrdem = [];
        foreach ($ids as $id) {
            if (isset($recusas[$id])) {
                $emOrdem[] = $recusas[$id];
            }
        }

        return $emOrdem;
    }
}
