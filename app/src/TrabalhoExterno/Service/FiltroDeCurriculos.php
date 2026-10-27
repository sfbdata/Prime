<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\MarcacaoDoUsuario;
use App\TrabalhoExterno\Enum\StatusCurriculo;

/**
 * Os filtros da lista do banco de talentos (desenho 06 l.993 a 995), os mesmos que a tela aplica,
 * para o CSV sair "da lista filtrada" (l.1150). `filtro` é a aba (Ativos, Novos, Favoritos,
 * Voluntários, Arquivados, Estrela, Importantes, Lixeira); estrela, importantes e lixeira são as
 * marcações pessoais de quem exporta. O termo é o do termo mais recente do currículo (`sem`, `enviado`, `aceito`).
 */
final class FiltroDeCurriculos
{
    public const COM_OAB = 'oab';
    public const ABAS = ['ativos', 'novo', 'favorito', 'voluntarios', 'arquivado', 'estrela', 'imp', 'lix'];

    public function __construct(
        private readonly CalculadoraDeImportanciaDoCurriculo $importancia,
    ) {
    }

    /**
     * @param list<Curriculo>                $curriculos
     * @param array<int, MarcacaoDoUsuario>  $marcas     id do currículo → marcação de quem filtra
     * @param array<string, string>          $filtros    filtro, busca, area, situacao, uf, termo
     * @param array<int, int>                $candidaturas id do currículo → candidaturas (pesa na aba Importantes)
     * @param array<int, string>             $termos     id do currículo → estado do termo mais recente (sem entrada = `sem`)
     *
     * @return list<Curriculo>
     */
    public function aplicar(array $curriculos, array $marcas, array $filtros, \DateTimeImmutable $agora, array $candidaturas = [], array $termos = []): array
    {
        $aba = in_array($filtros['filtro'] ?? '', self::ABAS, true) ? $filtros['filtro'] : 'ativos';

        return array_values(array_filter(
            $curriculos,
            fn (Curriculo $cv): bool => $this->passaNaAba($cv, $marcas[(int) $cv->getId()] ?? null, $aba, $agora, $candidaturas[(int) $cv->getId()] ?? 0)
                && $this->passaNosCampos($cv, $filtros, $termos[(int) $cv->getId()] ?? 'sem'),
        ));
    }

    /** `espF` e o filtro de aba (l.995): a marcação pessoal manda nas abas especiais. */
    private function passaNaAba(Curriculo $cv, ?MarcacaoDoUsuario $m, string $aba, \DateTimeImmutable $agora, int $candidaturas): bool
    {
        if ($m?->excluida() === true || ($aba === 'lix') !== ($m?->naLixeira() ?? false)) {
            return false;
        }

        return match ($aba) {
            'lix' => true,
            'estrela' => $m?->temEstrela() ?? false,
            'imp' => $this->importancia->calcular($cv, $m?->getImportante(), $agora, $candidaturas)['nivel'] >= 2,
            'ativos' => $cv->getStatus() !== StatusCurriculo::Arquivado,
            'voluntarios' => $cv->texto('vol') === 'sim' && $cv->getStatus() !== StatusCurriculo::Arquivado,
            default => $cv->getStatus()->value === $aba,
        };
    }

    /** `oab` é o filtro do KPI "COM OAB" do painel (spec §4.9, lacuna 1): advogado(a) e estagiário(a) com OAB. */
    private function passaNaSituacao(Curriculo $cv, string $situacao): bool
    {
        return match ($situacao) {
            '' => true,
            self::COM_OAB => $cv->getSituacao()->temOab(),
            default => $cv->getSituacao()->value === $situacao,
        };
    }

    /** @param array<string, string> $f */
    private function passaNosCampos(Curriculo $cv, array $f, string $termo): bool
    {
        $busca = mb_strtolower(trim($f['busca'] ?? ''));
        $texto = mb_strtolower(implode(' ', [
            $cv->texto('nome'), $cv->texto('oabNum'), $cv->texto('oabUf'), $cv->texto('cidade'),
            $cv->texto('uf'), $cv->texto('cargo'), implode(' ', $cv->getAreas()),
        ]));

        return $this->passaNaSituacao($cv, $f['situacao'] ?? '')
            && (($f['uf'] ?? '') === '' || $cv->texto('uf') === $f['uf'])
            && (($f['termo'] ?? '') === '' || $f['termo'] === $termo)
            && (($f['area'] ?? '') === '' || in_array($f['area'], $cv->getAreas(), true))
            && ($busca === '' || str_contains($texto, $busca));
    }
}
