<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\TermoDeVoluntariado;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O termo no recorte de quem lê, com os textos e as cores do desenho 06 de 25/10: `tmStatusTxt`/`tmStatusCor`
 * (cabeçalho do modal, l.1255), `termoTxt`/`termoCor` (botão do detalhe, l.1129) e `meuTermoTxt`/`meuTermoCor`
 * (Meu currículo, l.1123). NUNCA leva CPF nem RG: o snapshot só sai pelo HTML do termo, para quem pode ver.
 */
final class ApresentadorDeTermo
{
    public const COR_RASCUNHO = '#0a6fa0';
    public const COR_ENVIADO = '#9a5b00';
    public const COR_ACEITO = '#1e7b45';

    public function __construct(
        private readonly PoliticaDoTermo $politica,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** O estado na lista (`termo` do cartão): sem, enviado ou aceito. Sem dado pessoal. */
    public static function estado(?TermoDeVoluntariado $termo): string
    {
        return $termo?->getStatus()->value ?? 'sem';
    }

    /** @return array<string, mixed> */
    public function resumo(Contexto $ctx, TermoDeVoluntariado $termo): array
    {
        $aceito = $termo->getAceitoEm();
        $dia = $termo->getEnviadoEm()->setTimezone(new \DateTimeZone(HtmlDoTermoDeVoluntariado::FUSO))->format('d/m/Y');
        $quando = $aceito !== null ? HtmlDoTermoDeVoluntariado::dataHora($aceito) : '';
        $foiAceito = $termo->foiAceito();

        return [
            'codigo' => $termo->getCodigo(),
            'status' => $termo->getStatus()->value,
            'versao' => $termo->getVersaoModelo(),
            'enviado' => $dia,
            'aceito' => $aceito !== null ? $quando : null,
            'statusTxt' => $foiAceito ? 'Aceito eletronicamente em ' . $quando : 'Aguardando aceite do(a) voluntário(a) desde ' . $dia,
            'statusCor' => $foiAceito ? self::COR_ACEITO : self::COR_ENVIADO,
            'botaoTxt' => $foiAceito ? 'Termo de voluntariado aceito em ' . $quando : 'Termo enviado em ' . $dia . ' · aguardando aceite',
            'meuTxt' => $foiAceito ? 'Termo de voluntariado aceito em ' . $quando : 'Termo de voluntariado aguardando seu aceite',
            'cor' => $foiAceito ? self::COR_ACEITO : self::COR_ENVIADO,
            'podeAceitar' => $this->politica->podeAceitar($ctx, $termo),
            'documentoUrl' => $this->urls->generate('trabalho_externo_termo_documento', ['codigo' => $termo->getCodigo()]),
        ];
    }
}
