<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\DTO\FiltroDoPublico;
use App\Comercial\DTO\PublicoResolvido;
use App\Comercial\Repository\CadastradosRepository;
use App\Comercial\Repository\ConfirmacaoDeEnderecoRepository;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use Psr\Clock\ClockInterface;

/**
 * Os públicos da campanha (dc L.237-239 e L.534-539; spec §8.5), na confirmação e de novo a cada execução:
 *   - `leads` "Base de contatos": confirmados na lista `comercial_campanhas`; com o modelo `vagas` (§11, F3.3 a), a
 *     união sem repetidos dos confirmados de `comercial_campanhas` e de `vagas_alerta`, nessa ordem (quem está nas
 *     duas sai por `comercial_campanhas`). Com qualquer outro modelo, inclusive as cópias `cu<id>`, não usa
 *     `vagas_alerta` (R1);
 *   - `prim`, `sec`, `todos`: os cadastrados com vínculo ativo, usuário ativo e escritório válido (`todos` = a união,
 *     sem repetidos: quem é primário num escritório e secundário noutro conta uma vez);
 *   - `csv` e `indiv`: a lista informada, e só com autorização (B1, {@see PoliticaDeDestinatarios}).
 * Sempre sem repetidos (e-mail minúsculo, fica o primeiro) e sem os descadastrados de `comercial_campanhas` ou de
 * `todos` (a consulta da 0d, escopo plataforma). No modelo `vagas`, fica de fora também quem saiu de `vagas_alerta`:
 * o "não quero mais" de uma lista não volta pela outra. Cada destinatário leva a lista pela qual entrou
 * ({@see DestinatarioDaCampanha::$lista}): ela decide o link de descadastro, o List-Unsubscribe e a checagem do Carteiro.
 */
final class PublicosDaCampanha
{
    /** As chaves e os rótulos do dc (L.539), na ordem da grade. */
    public const ROTULOS = [
        'leads' => 'Base de contatos',
        'prim' => 'Usuários primários',
        'sec' => 'Usuários secundários',
        'todos' => 'Todos os cadastrados',
        'csv' => 'Importar lista (CSV)',
        'indiv' => 'Individual',
    ];
    /** A chave do "Ofertas · vagas abertas" ({@see ModelosDeCampanha}, dc L506). */
    public const MODELO_DE_VAGAS = 'vagas';
    /** A lista do "Receber alertas de vagas" (TrabalhoExterno), que o modelo `vagas` também alcança. */
    public const LISTA_DE_VAGAS = 'vagas_alerta';
    private const INFORMADOS = ['csv', 'indiv'];

    public function __construct(
        private readonly CadastradosRepository $cadastrados,
        private readonly ConfirmacaoDeEnderecoRepository $confirmacoes,
        private readonly DescadastroDeEmailInterface $descadastro,
        private readonly PoliticaDeDestinatarios $politica,
        private readonly AtributosDosDestinatarios $atributos,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * O público que recebe: sem repetidos, sem descadastrados, B1 e, por último, o "Filtrar destinatários" (§9), que
     * só busca os atributos quando há filtro. A idade e o aniversário contam a partir de `$hoje` (a execução da vez).
     *
     * `$modelo` é a chave do modelo da campanha: `vagas` alarga a Base de contatos (§11).
     *
     * @param list<DestinatarioDaCampanha> $informados a lista do CSV ou do Individual (ignorada nos outros)
     */
    public function resolver(string $publico, array $informados = [], ?FiltroDoPublico $filtro = null, ?\DateTimeImmutable $hoje = null, string $modelo = ''): PublicoResolvido
    {
        $fonte = $this->fonte($publico, $informados, $modelo);
        $unicos = self::semRepetidos($fonte);
        // dc 21.10 L665: "Y repetido(s) removido(s) · Z inválido(s) ignorado(s)".
        $invalidos = \count(array_filter($fonte, static fn (DestinatarioDaCampanha $d): bool => !$d->valido()));
        $repetidos = \count($fonte) - $invalidos - \count($unicos);
        $liberados = array_values(array_filter($unicos, fn (DestinatarioDaCampanha $d): bool => !$this->descadastrado($d, $modelo)));
        $autorizados = $this->autorizados($publico, $liberados);
        if ($filtro === null || !$filtro->ativo()) {
            return new PublicoResolvido($autorizados, \count($unicos) - \count($liberados), \count($liberados) - \count($autorizados), repetidos: $repetidos, invalidos: $invalidos);
        }
        $atributos = $this->atributos->de(array_map(static fn (DestinatarioDaCampanha $d): string => $d->email, $autorizados));
        [$atendem, $semInformacao] = FiltroDeDestinatarios::aplicar($autorizados, $atributos, $filtro, $hoje ?? $this->relogio->now());

        return new PublicoResolvido($atendem, \count($unicos) - \count($liberados), \count($liberados) - \count($autorizados), \count($autorizados), $semInformacao, $autorizados, $repetidos, $invalidos);
    }

    /**
     * O número de cada público da fonte na grade do dc (L.539: `listas.prim.length`, sem os filtros); o CSV e o
     * Individual contam o que foi lido. A Base de contatos segue o modelo, como no {@see self::resolver()}.
     *
     * @param list<DestinatarioDaCampanha> $informados
     *
     * @return array<string, int>
     */
    public function tamanhos(string $publico, array $informados, string $modelo = ''): array
    {
        $prim = array_column($this->cadastrados->primarios(), 'email');
        $sec = array_column($this->cadastrados->secundarios(), 'email');

        return [
            'leads' => \count(array_unique(array_map(static fn (DestinatarioDaCampanha $d): string => $d->email, $this->baseDeContatos($modelo)))),
            'prim' => \count($prim), 'sec' => \count($sec), 'todos' => \count(array_unique([...$prim, ...$sec])),
            'csv' => $publico === 'csv' ? self::validos($informados) : 0,
            'indiv' => $publico === 'indiv' ? self::validos($informados) : 0,
        ];
    }

    /**
     * @param list<DestinatarioDaCampanha> $informados
     *
     * @return list<DestinatarioDaCampanha>
     */
    private function fonte(string $publico, array $informados, string $modelo): array
    {
        return match ($publico) {
            'leads' => $this->baseDeContatos($modelo),
            'prim' => self::deLinhas($this->cadastrados->primarios()),
            'sec' => self::deLinhas($this->cadastrados->secundarios()),
            'todos' => self::deLinhas([...$this->cadastrados->primarios(), ...$this->cadastrados->secundarios()]),
            default => $informados,
        };
    }

    /**
     * Os confirmados de `comercial_campanhas` e, só no modelo `vagas`, depois deles os de `vagas_alerta` (com repetidos:
     * o {@see self::semRepetidos()} fica com a primeira ocorrência, a de `comercial_campanhas`).
     *
     * @return list<DestinatarioDaCampanha>
     */
    private function baseDeContatos(string $modelo): array
    {
        $base = [];
        foreach (self::listasDoModelo($modelo) as $lista) {
            foreach ($this->confirmacoes->confirmadosDaLista($lista) as $email) {
                $base[] = new DestinatarioDaCampanha($email, '', $lista);
            }
        }

        return $base;
    }

    /**
     * B1 (§8.5): no CSV e no Individual, só usuário ativo cadastrado ou confirmado em `comercial_campanhas`.
     *
     * @param list<DestinatarioDaCampanha> $lista
     *
     * @return list<DestinatarioDaCampanha>
     */
    private function autorizados(string $publico, array $lista): array
    {
        if (!\in_array($publico, self::INFORMADOS, true) || !$this->politica->exigirAutorizacaoEmCsvEIndividual()) {
            return $lista;
        }
        $emails = array_map(static fn (DestinatarioDaCampanha $d): string => $d->email, $lista);
        $permitidos = array_flip([...$this->cadastrados->ativosEntre($emails), ...$this->confirmacoes->confirmadosDaLista(ComposicaoDaCampanha::LISTA)]);

        return array_values(array_filter($lista, static fn (DestinatarioDaCampanha $d): bool => isset($permitidos[$d->email])));
    }

    /** @param list<DestinatarioDaCampanha> $lista */
    private static function validos(array $lista): int
    {
        return \count(array_filter($lista, static fn (DestinatarioDaCampanha $d): bool => $d->valido()));
    }

    /** No modelo `vagas`, o descadastro de qualquer uma das duas listas tira o destinatário (§11). */
    private function descadastrado(DestinatarioDaCampanha $d, string $modelo): bool
    {
        foreach (self::listasDoModelo($modelo) as $lista) {
            if ($this->descadastro->estaDescadastrado(null, $d->email, $lista)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> as listas de consentimento que o modelo alcança, na ordem em que valem */
    private static function listasDoModelo(string $modelo): array
    {
        return $modelo === self::MODELO_DE_VAGAS ? [ComposicaoDaCampanha::LISTA, self::LISTA_DE_VAGAS] : [ComposicaoDaCampanha::LISTA];
    }

    /**
     * @param list<DestinatarioDaCampanha> $lista
     *
     * @return list<DestinatarioDaCampanha>
     */
    private static function semRepetidos(array $lista): array
    {
        $vistos = [];
        $saida = [];
        foreach ($lista as $d) {
            if ($d->valido() && !isset($vistos[$d->email])) {
                $vistos[$d->email] = true;
                $saida[] = $d;
            }
        }

        return $saida;
    }

    /**
     * @param list<array{email: string, nome: string}> $linhas
     *
     * @return list<DestinatarioDaCampanha>
     */
    private static function deLinhas(array $linhas): array
    {
        return array_map(static fn (array $l): DestinatarioDaCampanha => new DestinatarioDaCampanha($l['email'], $l['nome']), $linhas);
    }
}
