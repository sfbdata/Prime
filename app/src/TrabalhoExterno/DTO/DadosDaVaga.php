<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\DTO;

use App\TrabalhoExterno\Exception\DadosInvalidos;

/**
 * O formulário da vaga (`tmVagaForm` do desenho VS 18.10.26, linhas 235–252). As listas são as
 * estáticas que vieram no trecho do script (linhas 833–839).
 */
final class DadosDaVaga
{
    public const AREAS = ['Cível', 'Trabalhista', 'Previdenciário', 'Família', 'Criminal', 'Tributário', 'Empresarial', 'Consumidor', 'Imobiliário', 'Condominial', 'Administrativo', 'Bancário'];
    public const CARGOS = ['Advogado(a)', 'Advogado(a) associado(a)', 'Correspondente', 'Estagiário(a)', 'Assistente jurídico', 'Controladoria', 'Administrativo'];
    public const CONTRATACOES = ['CLT', 'PJ / Associado', 'Estágio', 'Por demanda'];
    public const MODALIDADES = ['Presencial', 'Híbrido', 'Remoto'];
    /**
     * Dias de divulgação ("QUANTO TEMPO A VAGA FICA DISPONÍVEL", `perOps`, desenho l.1185). O 0 é "Até a
     * vaga ser preenchida", só para o Master; os demais ficam no teto `maxDias` da configuração.
     */
    public const PERIODOS = [0, 1, 3, 7, 15, 30, 60];
    /** Minutos da oferta com tempo ("DISPONÍVEL POR", `DUR` l.933). Valor antigo fora da lista (120, 1440) cai no padrão de 15. */
    public const DURACOES_DA_OFERTA = [5, 10, 15, 30, 60];
    /** Dados de quem oferece com o olho (`ofCampos`, l.1182); `local` é cidade e UF juntas. */
    public const CAMPOS_DO_OFERTANTE = ['resp', 'local', 'email', 'tel', 'site'];
    /** O que nasce oculto: e-mail e telefone; os candidatos falam com quem oferece pelo BlueJus (l.1182). */
    public const OCULTOS_PADRAO = ['email', 'tel'];

    public string $titulo = '';
    public string $cargo = '';
    public string $contratacao = '';
    public string $modalidade = '';
    public string $cidade = '';
    public string $uf = '';
    public string $remuneracao = '';
    /** @var list<string> */
    public array $areas = [];
    public string $requisitos = '';
    public ?\DateTimeImmutable $inscricoesAte = null;
    public bool $exigeOab = false;
    public int $periodoDias = 15;
    public bool $oferta = false;
    public int $duracaoDaOferta = 15;
    public int $qtd = 1;
    /** O Master escolheu o tipo (vaga comum ou oferta com tempo); sem isso, a aprovação aplica o padrão (`tipoDef`, l.979). */
    public bool $ofertaDefinida = false;
    /** @var array{nome: string, resp: string, cidade: string, uf: string, email: string, tel: string, site: string, ocultos: list<string>} */
    public array $ofertante = ['nome' => '', 'resp' => '', 'cidade' => '', 'uf' => '', 'email' => '', 'tel' => '', 'site' => '', 'ocultos' => []];

    /** @param array<string, mixed> $d */
    public static function deArray(array $d): self
    {
        $x = new self();
        $x->titulo = self::texto($d['titulo'] ?? '', 150);
        $x->cargo = self::daLista($d['cargo'] ?? '', self::CARGOS);
        $x->contratacao = self::daLista($d['contratacao'] ?? '', self::CONTRATACOES);
        $x->modalidade = self::daLista($d['modalidade'] ?? '', self::MODALIDADES);
        $x->cidade = self::texto($d['cidade'] ?? '', 120);
        $x->uf = self::daLista($d['uf'] ?? '', DadosDaSolicitacao::UFS);
        $x->remuneracao = self::texto($d['remuneracao'] ?? '', 120);
        $x->areas = array_values(array_intersect(self::AREAS, is_array($d['areas'] ?? null) ? $d['areas'] : []));
        $x->requisitos = self::texto($d['requisitos'] ?? '', 5000);
        $x->inscricoesAte = self::data($d['inscricoesAte'] ?? '');
        $x->exigeOab = (bool) ($d['exigeOab'] ?? false);
        $x->periodoDias = self::opcao($d['periodoDias'] ?? null, self::PERIODOS, 15);
        $x->ofertaDefinida = array_key_exists('oferta', $d);
        $x->oferta = (bool) ($d['oferta'] ?? false);
        $x->duracaoDaOferta = self::opcao($d['duracaoDaOferta'] ?? null, self::DURACOES_DA_OFERTA, 15);
        $x->qtd = max(1, min(20, (int) ($d['qtd'] ?? 1)));
        $x->ofertante = self::ofertante(is_array($d['ofertante'] ?? null) ? $d['ofertante'] : []);

        return $x;
    }

    /** Os obrigatórios do desenho, na ordem e com as mensagens dele (l.1005). */
    public function exigirCompleta(): void
    {
        match (true) {
            $this->ofertante['nome'] === '' => throw new DadosInvalidos('Informe o escritório que está oferecendo a vaga.'),
            $this->titulo === '' => throw new DadosInvalidos('Informe o título da vaga.'),
            $this->cargo === '' => throw new DadosInvalidos('Escolha o cargo.'),
            default => null,
        };
    }

    /**
     * Quem não é Master não escolhe o tipo e fica no teto de dias da Central, quando há teto (l.1005:
     * `cur && cur <= mx ? cur : Math.min(15, mx)`). Sem teto, pode "Até a vaga ser preenchida" (l.1185).
     */
    public function limitarParaQuemNaoEMaster(int $maxDias): void
    {
        $this->oferta = false;
        $this->ofertaDefinida = false;
        if ($maxDias > 0 && ($this->periodoDias === 0 || $this->periodoDias > $maxDias)) {
            $this->periodoDias = min(15, $maxDias);
        }
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array{nome: string, resp: string, cidade: string, uf: string, email: string, tel: string, site: string, ocultos: list<string>}
     */
    private static function ofertante(array $o): array
    {
        return [
            'nome' => self::texto($o['nome'] ?? '', 150),
            'resp' => self::texto($o['resp'] ?? '', 150),
            'cidade' => self::texto($o['cidade'] ?? '', 120),
            'uf' => self::daLista($o['uf'] ?? '', DadosDaSolicitacao::UFS),
            'email' => self::texto($o['email'] ?? '', 180),
            'tel' => self::texto($o['tel'] ?? '', 40),
            'site' => self::texto($o['site'] ?? '', 200),
            'ocultos' => is_array($o['ocultos'] ?? null)
                ? array_values(array_intersect(self::CAMPOS_DO_OFERTANTE, $o['ocultos']))
                : self::OCULTOS_PADRAO,
        ];
    }

    /** @param list<int> $opcoes */
    private static function opcao(mixed $v, array $opcoes, int $padrao): int
    {
        return is_numeric($v) && in_array((int) $v, $opcoes, true) ? (int) $v : $padrao;
    }

    private static function texto(mixed $v, int $max): string
    {
        return mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max);
    }

    /** @param list<string> $lista */
    private static function daLista(mixed $v, array $lista): string
    {
        $v = self::texto($v, 100);

        return in_array($v, $lista, true) ? $v : '';
    }

    private static function data(mixed $v): ?\DateTimeImmutable
    {
        $v = self::texto($v, 10);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v ? $d : null;
    }
}
