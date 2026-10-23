<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\DTO;

use App\TrabalhoExterno\Enum\SituacaoAcademica;
use App\TrabalhoExterno\Exception\DadosInvalidos;

/**
 * O formulário do currículo (`tmCvForm` do desenho 06, l.254 a 283; valores l.1171 a 1189). Os
 * campos que o formulário esconde pela situação (bloco da OAB, bloco do curso) chegam vazios ao
 * servidor: o que não aparece na tela não fica gravado.
 */
final class DadosDoCurriculo
{
    public const VOLUNTARIO = ['sim', 'talvez', 'nao'];
    public const NIVEIS_DE_IDIOMA = ['Básico', 'Intermediário', 'Avançado', 'Fluente', 'Nativo'];
    private const MAX_ITENS = 20;

    public string $nome = '';
    public string $email = '';
    public string $tel = '';
    public string $insta = '';
    public ?SituacaoAcademica $situacao = null;
    public string $oabNum = '';
    public string $oabUf = '';
    public string $inst = '';
    public string $sem = '';
    public string $cargo = '';
    public string $cidade = '';
    public string $uf = '';
    public string $modalidade = '';
    public ?int $anos = null;
    public string $resumo = '';
    public string $pretensao = '';
    public string $vol = '';
    public string $tituloP = '';
    public string $linkedin = '';
    public string $lattes = '';
    public string $habs = '';
    /** @var list<array{cargo: string, org: string, ini: string, fim: string, desc: string}> */
    public array $exps = [];
    /** @var list<array{curso: string, inst: string, ini: string, fim: string}> */
    public array $forms = [];
    /** @var list<array{lng: string, nivel: string}> */
    public array $idis = [];
    /** @var list<string> */
    public array $areas = [];
    public bool $lgpd = false;

    /** @param array<string, mixed> $d */
    public static function deArray(array $d): self
    {
        $x = new self();
        $x->identificacao($d);
        $x->situacaoAcademica($d);
        $x->preferencias($d);
        $x->apresentacao($d);
        $x->exps = self::lista($d['exps'] ?? null, ['cargo' => 150, 'org' => 150, 'ini' => 20, 'fim' => 20, 'desc' => 2000], ['cargo', 'org']);
        $x->forms = self::lista($d['forms'] ?? null, ['curso' => 150, 'inst' => 150, 'ini' => 20, 'fim' => 20], ['curso', 'inst']);
        $x->idis = array_map(
            static fn (array $i): array => ['lng' => $i['lng'], 'nivel' => in_array($i['nivel'], self::NIVEIS_DE_IDIOMA, true) ? $i['nivel'] : ''],
            self::lista($d['idis'] ?? null, ['lng' => 60, 'nivel' => 20], ['lng']),
        );
        $x->areas = array_values(array_intersect(DadosDaVaga::AREAS, is_array($d['areas'] ?? null) ? $d['areas'] : []));
        $x->lgpd = ($d['lgpd'] ?? false) === true;

        return $x;
    }

    /**
     * As validações do desenho, na ordem e com o texto dele (l.1006), e depois as do servidor
     * (decisão 5 da spec §4.5): OAB só com dígitos, UF da lista, LinkedIn e Lattes em https no
     * domínio de cada um.
     */
    public function exigirValido(): void
    {
        $sit = $this->situacao;
        match (true) {
            $this->nome === '' => throw new DadosInvalidos('Informe o nome completo.'),
            preg_match('/^\S+@\S+\.\S+$/u', $this->email) !== 1 => throw new DadosInvalidos('Informe um e-mail válido.'),
            $sit === null => throw new DadosInvalidos('Informe sua situação: advogado(a), estagiário(a) com OAB, estudante ou bacharel(a).'),
            $sit->temOab() && $this->oabNum === '' => throw new DadosInvalidos('Informe o número de inscrição na OAB.'),
            $sit->exigeInstituicao() && $this->inst === '' => throw new DadosInvalidos('Informe a instituição de ensino.'),
            !$this->lgpd => throw new DadosInvalidos('É preciso autorizar o uso dos dados (LGPD) para enviar o currículo.'),
            default => null,
        };
        $this->exigirFormatos();
    }

    private function exigirFormatos(): void
    {
        $ufs = DadosDaSolicitacao::UFS;
        match (true) {
            $this->oabNum !== '' && preg_match('/^\d{1,7}$/', $this->oabNum) !== 1 => throw new DadosInvalidos('Informe o número da OAB só com números, até 7 dígitos.'),
            $this->oabUf !== '' && !in_array($this->oabUf, $ufs, true) => throw new DadosInvalidos('Escolha a UF da OAB na lista.'),
            $this->uf !== '' && !in_array($this->uf, $ufs, true) => throw new DadosInvalidos('Escolha a UF na lista.'),
            $this->linkedin !== '' && !self::enderecoDe($this->linkedin, 'linkedin.com') => throw new DadosInvalidos('Informe o LinkedIn com o endereço https do perfil em linkedin.com.'),
            $this->lattes !== '' && !self::enderecoDe($this->lattes, 'lattes.cnpq.br') => throw new DadosInvalidos('Informe o Lattes com o endereço https em lattes.cnpq.br.'),
            default => null,
        };
    }

    /** @param array<string, mixed> $d */
    private function identificacao(array $d): void
    {
        $this->nome = self::texto($d['nome'] ?? '', 150);
        $this->email = self::texto($d['email'] ?? '', 180);
        $this->tel = self::texto($d['tel'] ?? '', 40);
        $this->insta = self::instagram($d['insta'] ?? '');
    }

    /** @param array<string, mixed> $d */
    private function situacaoAcademica(array $d): void
    {
        // A tela manda `situacao` e, como sinônimo, `sit` (o nome do desenho).
        $this->situacao = SituacaoAcademica::tryFrom(self::texto($d['situacao'] ?? $d['sit'] ?? '', 10));
        if ($this->situacao?->temOab() === true) {
            $this->oabNum = self::texto($d['oabNum'] ?? '', 20);
            $this->oabUf = mb_strtoupper(self::texto($d['oabUf'] ?? '', 2));
        }
        if ($this->situacao?->temCurso() === true) {
            $this->inst = self::texto($d['inst'] ?? '', 150);
            $this->sem = self::texto($d['sem'] ?? '', 40);
        }
    }

    /** @param array<string, mixed> $d */
    private function preferencias(array $d): void
    {
        $this->cargo = self::daLista($d['cargo'] ?? '', DadosDaVaga::CARGOS);
        $this->cidade = self::texto($d['cidade'] ?? '', 120);
        $this->uf = mb_strtoupper(self::texto($d['uf'] ?? '', 2));
        $this->modalidade = self::daLista($d['modalidade'] ?? '', DadosDaVaga::MODALIDADES);
        $anos = $d['anos'] ?? null;
        $this->anos = is_numeric($anos) && (int) $anos >= 0 ? min(80, (int) $anos) : null;
        $this->pretensao = self::texto($d['pretensao'] ?? '', 120);
        $this->vol = self::daLista($d['vol'] ?? '', self::VOLUNTARIO);
    }

    /** @param array<string, mixed> $d */
    private function apresentacao(array $d): void
    {
        $this->resumo = self::texto($d['resumo'] ?? '', 5000);
        $this->tituloP = self::texto($d['tituloP'] ?? '', 200);
        $this->linkedin = self::texto($d['linkedin'] ?? '', 255);
        $this->lattes = self::texto($d['lattes'] ?? '', 255);
        $this->habs = self::texto($d['habs'] ?? '', 1000);
    }

    private static function enderecoDe(string $url, string $dominio): bool
    {
        $partes = parse_url($url);
        $host = is_array($partes) ? mb_strtolower((string) ($partes['host'] ?? '')) : '';

        return is_array($partes) && ($partes['scheme'] ?? '') === 'https'
            && ($host === $dominio || str_ends_with($host, '.' . $dominio));
    }

    /** O mesmo corte do campo do desenho (`tfOnInsta`, l.1178): sem @, sem o endereço, sem espaços. */
    private static function instagram(mixed $v): string
    {
        $v = (string) preg_replace('/^@+/', '', self::texto($v, 200));
        $v = (string) preg_replace('#^https?://(www\.)?instagram\.com/#i', '', $v);
        $v = (string) preg_replace('/[\/?#].*$/', '', $v);

        return mb_substr((string) preg_replace('/\s/', '', $v), 0, 60);
    }

    /**
     * Experiências, formações e idiomas: só os itens com algum dos campos `$exige` preenchido (l.1006).
     *
     * @param array<string, int> $campos  campo → tamanho máximo
     * @param list<string>       $exige
     *
     * @return list<array<string, string>>
     */
    private static function lista(mixed $itens, array $campos, array $exige): array
    {
        $lista = [];
        foreach (is_array($itens) ? $itens : [] as $item) {
            $item = is_array($item) ? $item : [];
            $linha = [];
            foreach ($campos as $campo => $max) {
                $linha[$campo] = self::texto($item[$campo] ?? '', $max);
            }
            if (array_filter(array_intersect_key($linha, array_flip($exige)), static fn (string $v): bool => $v !== '') !== []) {
                $lista[] = $linha;
            }
        }

        return array_slice($lista, 0, self::MAX_ITENS);
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
}
