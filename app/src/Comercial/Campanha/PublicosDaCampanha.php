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
 *   - `leads` "Base de contatos": confirmados na lista `comercial_campanhas` (hoje nenhum formulário pede esse
 *     consentimento: o público mostra 0; não usa `vagas_alerta`, R1);
 *   - `prim`, `sec`, `todos`: os cadastrados com vínculo ativo, usuário ativo e escritório válido (`todos` = a união,
 *     sem repetidos: quem é primário num escritório e secundário noutro conta uma vez);
 *   - `csv` e `indiv`: a lista informada, e só com autorização (B1, {@see PoliticaDeDestinatarios}).
 * Sempre sem repetidos (e-mail minúsculo, fica o primeiro) e sem os descadastrados de `comercial_campanhas` ou de
 * `todos` (a consulta da 0d, escopo plataforma).
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
     * @param list<DestinatarioDaCampanha> $informados a lista do CSV ou do Individual (ignorada nos outros)
     */
    public function resolver(string $publico, array $informados = [], ?FiltroDoPublico $filtro = null, ?\DateTimeImmutable $hoje = null): PublicoResolvido
    {
        $unicos = self::semRepetidos($this->fonte($publico, $informados));
        $liberados = array_values(array_filter($unicos, fn (DestinatarioDaCampanha $d): bool => !$this->descadastrado($d)));
        $autorizados = $this->autorizados($publico, $liberados);
        if ($filtro === null || !$filtro->ativo()) {
            return new PublicoResolvido($autorizados, \count($unicos) - \count($liberados), \count($liberados) - \count($autorizados));
        }
        $atributos = $this->atributos->de(array_map(static fn (DestinatarioDaCampanha $d): string => $d->email, $autorizados));
        [$atendem, $semInformacao] = FiltroDeDestinatarios::aplicar($autorizados, $atributos, $filtro, $hoje ?? $this->relogio->now());

        return new PublicoResolvido($atendem, \count($unicos) - \count($liberados), \count($liberados) - \count($autorizados), \count($autorizados), $semInformacao);
    }

    /**
     * O número de cada público da fonte na grade do dc (L.539: `listas.prim.length`, sem os filtros); o CSV e o
     * Individual contam o que foi lido.
     *
     * @param list<DestinatarioDaCampanha> $informados
     *
     * @return array<string, int>
     */
    public function tamanhos(string $publico, array $informados): array
    {
        $prim = array_column($this->cadastrados->primarios(), 'email');
        $sec = array_column($this->cadastrados->secundarios(), 'email');

        return [
            'leads' => \count($this->confirmacoes->confirmadosDaLista(ComposicaoDaCampanha::LISTA)),
            'prim' => \count($prim), 'sec' => \count($sec), 'todos' => \count(array_unique([...$prim, ...$sec])),
            'csv' => $publico === 'csv' ? \count($informados) : 0,
            'indiv' => $publico === 'indiv' ? \count($informados) : 0,
        ];
    }

    /**
     * @param list<DestinatarioDaCampanha> $informados
     *
     * @return list<DestinatarioDaCampanha>
     */
    private function fonte(string $publico, array $informados): array
    {
        return match ($publico) {
            'leads' => array_map(static fn (string $e): DestinatarioDaCampanha => new DestinatarioDaCampanha($e), $this->confirmacoes->confirmadosDaLista(ComposicaoDaCampanha::LISTA)),
            'prim' => self::deLinhas($this->cadastrados->primarios()),
            'sec' => self::deLinhas($this->cadastrados->secundarios()),
            'todos' => self::deLinhas([...$this->cadastrados->primarios(), ...$this->cadastrados->secundarios()]),
            default => $informados,
        };
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

    private function descadastrado(DestinatarioDaCampanha $d): bool
    {
        return $this->descadastro->estaDescadastrado(null, $d->email, ComposicaoDaCampanha::LISTA);
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
