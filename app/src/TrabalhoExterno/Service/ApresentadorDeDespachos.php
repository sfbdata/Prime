<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\DespachoDemanda;
use App\TrabalhoExterno\Entity\DocumentoDemanda;
use App\TrabalhoExterno\Enum\NaturezaDoDespacho;
use App\TrabalhoExterno\Enum\Perfil;

/**
 * O DESPACHO do Resumo e o Extrato (desenho 06 de 24/10, l.512 a 530 e 1391 a 1394), já no recorte de quem lê:
 * o nome de quem é da Central como o leitor externo o vê ({@see PoliticaDaDemanda::nomeVisivel()}) e os anexos
 * só para quem vê os documentos (o Convidado sem "Ver instruções" lê o despacho, sem o nome dos arquivos).
 */
final class ApresentadorDeDespachos
{
    private const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    public function __construct(
        private readonly PoliticaDaDemanda $politica,
        private readonly PrazoDoDespacho $prazos,
    ) {
    }

    /** @return array<string, mixed> */
    public function bloco(Contexto $ctx, DemandaExterna $d, ?\DateTimeImmutable $hoje = null): array
    {
        $hoje ??= new \DateTimeImmutable('today');
        $master = $ctx->perfil === Perfil::Master;
        $pode = $this->politica->podeDespachar($ctx, $d);
        // `rOn && nD`: a chave ligada e ao menos um despacho.
        $aceita = $d->aceitaRespostaAoDespacho();
        $docs = $this->politica->podeVerDocumentos($ctx, $d);

        $itens = $d->getDespachos();
        $numero = 0;
        $numeros = [];
        foreach ($itens as $i => $x) {
            if (!$x->ehResposta()) {
                $numeros[$i] = ++$numero;
            }
        }
        $lista = [];
        foreach ($itens as $i => $x) {
            $lista[] = $this->item($ctx, $x, $numeros[$i] ?? 0, $docs);
        }

        return [
            'podeDespachar' => $pode,
            'podeResponder' => $this->politica->podeResponderAoDespacho($ctx, $d),
            'respostasLiberadas' => $d->respostasAoDespachoLiberadas(),
            'respInfo' => !$master && $aceita,
            'leSem' => !$master && !$aceita,
            'naturezas' => array_map(static fn (NaturezaDoDespacho $n): array => ['k' => $n->value, 'r' => $n->rotulo()], NaturezaDoDespacho::cases()),
            'prazoPadrao' => $pode ? $this->prazos->padrao($hoje)->format('Y-m-d') : '',
            'feriados' => $pode ? $this->prazos->feriadosEmDiaDeSemana($hoje) : [],
            // `cur.desp.slice().reverse()`: o mais novo em cima.
            'lista' => array_reverse($lista),
        ];
    }

    /**
     * O Extrato para impressão (`dpExtrato`), como no desenho: na ordem de publicação, cada linha "DESPACHO {n}"
     * contando também as respostas, o nome como gravado (sem caixa alta) e a data com os segundos.
     *
     * @return list<array{titulo: string, txt: string, ass: string}>
     */
    public function extrato(Contexto $ctx, DemandaExterna $d): array
    {
        $saida = [];
        foreach ($d->getDespachos() as $i => $x) {
            $saida[] = [
                'titulo' => 'DESPACHO ' . ($i + 1) . ($x->getNatureza() !== null ? ' · ' . $x->getNatureza()->rotulo() : ''),
                'txt' => $x->getTexto(),
                'ass' => 'Assinado eletronicamente por ' . $this->por($ctx, $x) . ' em ' . $x->getEm()->format('d/m/Y, H:i:s'),
            ];
        }

        return $saida;
    }

    /** @return array<string, mixed> */
    private function item(Contexto $ctx, DespachoDemanda $x, int $numero, bool $docs): array
    {
        $resposta = $x->ehResposta();
        $por = $this->por($ctx, $x);

        return [
            'tipo' => $x->getTipo()->value,
            'n' => $resposta ? 'RESPOSTA DE ' . mb_strtoupper($por) : 'DESPACHO ' . $numero,
            'nat' => $x->getNatureza()?->rotulo() ?? '',
            'prazo' => $x->getPrazo() !== null ? 'Prazo: ' . $x->getPrazo()->format('d/m/Y') : '',
            'txt' => $x->getTexto(),
            'arqs' => $docs ? array_map(static fn (DocumentoDemanda $doc): array => ['id' => $doc->getId(), 'nome' => $doc->getNomeOriginal()], $x->getDocumentos()) : [],
            'local' => $x->getCidade() . ', ' . self::porExtenso($x->getEm()) . '.',
            'ass' => 'Assinado eletronicamente por ' . self::assinante($x, $por) . ' em ' . $x->getEm()->format('d/m/Y, H:i') . ' · Cód. ' . $x->codigoCurto(),
        ];
    }

    /**
     * Quem assina na lista (`String(x.por || 'Master').toUpperCase() + ' (Central BlueJus)'`): o ÚNICO ponto que
     * monta o assinante. O nome segue a privacidade que já existe ({@see PoliticaDaDemanda::nomeVisivel()},
     * `priv.nomeResp`); quando o leitor vê "Central BlueJus" no lugar do nome, o "(Central BlueJus)" sairia
     * repetido e fica de fora (decisão do dono de 24/10): "CENTRAL BLUEJUS".
     */
    private static function assinante(DespachoDemanda $x, string $por): string
    {
        $nome = $por !== '' ? $por : 'Master';
        $daCentral = !$x->ehResposta() && $nome !== PoliticaDaDemanda::NOME_DA_CENTRAL;

        return mb_strtoupper($nome) . ($daCentral ? ' (Central BlueJus)' : '');
    }

    private function por(Contexto $ctx, DespachoDemanda $x): string
    {
        return $this->politica->nomeVisivel($ctx, $x->getNomeAutor(), $x->getPerfilAutor());
    }

    /** `toLocaleDateString('pt-BR', { day: 'numeric', month: 'long', year: 'numeric' })`: "4 de outubro de 2026". */
    private static function porExtenso(\DateTimeImmutable $em): string
    {
        return $em->format('j') . ' de ' . self::MESES[(int) $em->format('n') - 1] . ' de ' . $em->format('Y');
    }
}
