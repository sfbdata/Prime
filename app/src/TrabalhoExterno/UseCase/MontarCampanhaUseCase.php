<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\DTO\FiltroDaCampanha;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\DestinatariosDaCampanha;
use App\TrabalhoExterno\Service\EmailDaCampanha;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;

/**
 * `GET /trabalho-externo/api/campanha` (spec VS 18.10.26 §4.10): o modal "Campanha por e-mail · Master"
 * (desenho 06 l.330 a 341, 1082 a 1086, 1168 e 1169). Quem: só o Master de verdade; a Equipe, o externo e
 * a prévia (`?como=`) são negados sem ler nada. Monta os públicos, os filtros, o assunto, o resumo e a
 * prévia do e-mail com o link da página pública geral. Consulta: não grava nada (nem link de vagas).
 */
final class MontarCampanhaUseCase
{
    public const SO_O_MASTER = 'Campanha por e-mail disponível só para o Master.';

    public function __construct(
        private readonly PoliticaDoCurriculo $politica,
        private readonly DestinatariosDaCampanha $destinatarios,
        private readonly EmailDaCampanha $email,
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(Contexto $ctx, FiltroDaCampanha $filtro): array
    {
        self::exigirMaster($this->politica, $ctx);
        $abertas = $this->email->vagasAbertas($filtro, new \DateTimeImmutable());
        $dest = $this->destinatarios->para($filtro);
        $padrao = $this->email->assuntoPadrao($filtro, count($abertas));

        return [
            'pub' => $filtro->pub,
            'publicos' => $this->destinatarios->publicos(),
            'ufs' => DadosDaSolicitacao::UFS,
            'areas' => DadosDaVaga::AREAS,
            'uf' => $filtro->uf,
            'area' => $filtro->area,
            'assunto' => $filtro->assunto ?? $padrao,
            'assuntoPadrao' => $padrao,
            'destinatarios' => count($dest['lista']),
            'deFora' => $dest['deFora'],
            'vagas' => count($abertas),
            'html' => $this->email->html($filtro, $abertas, $this->email->linkPublico($filtro)),
            'resumo' => self::resumo($filtro, count($dest['lista']), count($abertas)),
        ];
    }

    /** O Master de verdade, na aba: a mesma porta das três exportações. */
    public static function exigirMaster(PoliticaDoCurriculo $politica, Contexto $ctx): void
    {
        if (!$politica->ehMaster($ctx) || !$politica->veAba($ctx)) {
            throw new AcaoNaoPermitida(self::SO_O_MASTER);
        }
    }

    /** `cpNTxt` e `cpVagasTxt` (l.1169). @return array{destinatarios: string, vagas: string} */
    private static function resumo(FiltroDaCampanha $filtro, int $destinatarios, int $vagas): array
    {
        return [
            'destinatarios' => $destinatarios . ' destinatário(s) com autorização (LGPD)',
            'vagas' => $filtro->ehEscritorios() ? 'Modelo para captar escritórios' : $vagas . ' vaga(s) aberta(s) no e-mail',
        ];
    }
}
