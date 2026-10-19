<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\Entity\HistoricoVaga;
use App\TrabalhoExterno\Entity\MarcacaoDoUsuario;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\SituacaoVaga;

/**
 * A vaga no recorte de quem lê: cartão da grade (linhas 158–166 do desenho) e detalhe (`tmVaga`,
 * linhas 285–300). Os campos de contato que o ofertante escondeu não saem para quem não é da
 * Central nem dono da vaga, nem no JSON.
 */
final class ApresentadorDeVaga
{
    /** "NOVA": aberta publicada há menos disso. Provisório até o zip. */
    private const HORAS_DE_NOVA = 24;

    public function __construct(
        private readonly PoliticaDaVaga $politica,
    ) {
    }

    /** @return array<string, mixed> */
    public function cartao(Contexto $ctx, Vaga $v, ?MarcacaoDoUsuario $marca, \DateTimeImmutable $agora): array
    {
        $s = $v->situacaoEm($agora);
        $publicada = $v->getPublicadaEm();

        return [
            'codigo' => $v->getCodigo(),
            'titulo' => $v->getTitulo(),
            'escritorio' => (string) ($v->getOfertante()['nome'] ?? ''),
            'situacao' => $s->value,
            'situacaoRotulo' => $s->rotulo(),
            'situacaoCor' => $s->cor(),
            'pendente' => $s === SituacaoVaga::Pendente,
            'cargo' => $v->getCargo(),
            'contratacao' => $v->getContratacao(),
            'local' => $this->local($v),
            'remuneracao' => $v->getRemuneracao() !== '' ? $v->getRemuneracao() : 'A combinar',
            'areas' => $v->getAreas(),
            'prazo' => $v->getInscricoesAte()?->format('Y-m-d'),
            'oferta' => $v->isOferta() && $s === SituacaoVaga::Aberta,
            'expiraEm' => $v->isOferta() ? $v->getExpiraEm()?->format(\DATE_ATOM) : null,
            'publicadaEm' => $publicada?->format(\DATE_ATOM),
            'fimDivulgacao' => $v->getFimDivulgacao()?->format(\DATE_ATOM),
            'nova' => $s === SituacaoVaga::Aberta && $publicada !== null && $publicada > $agora->modify('-' . self::HORAS_DE_NOVA . ' hours'),
            'daCentral' => $v->ehDaCentral(),
            'minha' => $this->politica->ehDono($ctx, $v),
            'estrela' => $marca?->temEstrela() ?? false,
            'lixeira' => $marca?->naLixeira() ?? false,
            'importante' => $marca?->getImportante(),
            'vista' => $marca !== null && !$marca->isNaoLida(),
            'botoes' => $this->politica->botoes($ctx, $v, $agora),
        ];
    }

    /** @return array<string, mixed> */
    public function detalhe(Contexto $ctx, Vaga $v, ?MarcacaoDoUsuario $marca, \DateTimeImmutable $agora): array
    {
        return $this->cartao($ctx, $v, $marca, $agora) + [
            'modalidade' => $v->getModalidade(),
            'requisitos' => $v->getRequisitos(),
            'exigeOab' => $v->isExigeOab(),
            'periodoDias' => $v->getPeriodoDias(),
            'qtd' => $v->getQtd(),
            'motivo' => $this->politica->veContatoOculto($ctx, $v) ? $v->getMotivo() : null,
            'ofertante' => $this->ofertante($ctx, $v),
            'historico' => $this->politica->veContatoOculto($ctx, $v) ? array_map(static fn (HistoricoVaga $h): array => [
                'ic' => $h->getIcone(),
                'cor' => $h->getCor(),
                'txt' => $h->getTexto(),
                'em' => $h->getEm()->format('d/m/Y H:i'),
            ], $v->getHistorico()) : [],
        ];
    }

    /**
     * "OFERECIDO POR": nome sempre; os demais campos só se o ofertante não os escondeu, ou para quem
     * é da Central ou dono. `oculto` diz se há algo escondido ("Contato pelo BlueJus").
     *
     * @return array{nome: string, campos: list<array{k: string, v: string, oculto: bool}>, oculto: bool}
     */
    private function ofertante(Contexto $ctx, Vaga $v): array
    {
        $o = $v->getOfertante();
        $ocultos = is_array($o['ocultos'] ?? null) ? $o['ocultos'] : [];
        $veTudo = $this->politica->veContatoOculto($ctx, $v);
        $campos = [];
        foreach (DadosDaVaga::CAMPOS_DO_OFERTANTE as $k) {
            $valor = (string) ($o[$k] ?? '');
            $oculto = in_array($k, $ocultos, true);
            if ($valor !== '' && ($veTudo || !$oculto)) {
                $campos[] = ['k' => $k, 'v' => $valor, 'oculto' => $oculto];
            }
        }

        return ['nome' => (string) ($o['nome'] ?? ''), 'campos' => $campos, 'oculto' => !$veTudo && $ocultos !== []];
    }

    private function local(Vaga $v): string
    {
        $partes = array_filter([$v->getCidade() !== '' ? $v->getCidade() . ($v->getUf() !== '' ? '/' . $v->getUf() : '') : $v->getUf(), $v->getModalidade()]);

        return $partes !== [] ? implode(' · ', $partes) : 'A definir';
    }
}
