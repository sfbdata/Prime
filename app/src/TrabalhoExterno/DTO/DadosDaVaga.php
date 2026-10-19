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
    /** Dias de divulgação (`DUR`). */
    public const PERIODOS = [5, 10, 15, 30, 60];
    /** Minutos da oferta relâmpago ("DISPONÍVEL POR"). Provisório até o zip: `tfDurs` se perdeu. */
    public const DURACOES_DA_OFERTA = [5, 10, 15, 30, 60];
    /** Dados de quem oferece que cada um pode esconder na vaga (o "olho" de `ofCampos`). */
    public const CAMPOS_DO_OFERTANTE = ['resp', 'cidade', 'uf', 'email', 'tel', 'site'];

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
        $x->oferta = (bool) ($d['oferta'] ?? false);
        $x->duracaoDaOferta = self::opcao($d['duracaoDaOferta'] ?? null, self::DURACOES_DA_OFERTA, 15);
        $x->qtd = max(1, min(20, (int) ($d['qtd'] ?? 1)));
        $x->ofertante = self::ofertante(is_array($d['ofertante'] ?? null) ? $d['ofertante'] : []);

        return $x;
    }

    /** Os obrigatórios do desenho: TÍTULO DA VAGA *, CARGO * e ESCRITÓRIO * (de quem oferece). */
    public function exigirCompleta(): void
    {
        if ($this->titulo === '' || $this->cargo === '' || $this->ofertante['nome'] === '') {
            throw new DadosInvalidos('Preencha os campos obrigatórios marcados com *.');
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
            'ocultos' => array_values(array_intersect(self::CAMPOS_DO_OFERTANTE, is_array($o['ocultos'] ?? null) ? $o['ocultos'] : [])),
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
