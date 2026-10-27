<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\TermoDeVoluntariado;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\TermoDeVoluntariadoRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\PoliticaDoTermo;
use App\TrabalhoExterno\Service\RascunhoDoTermo;

/**
 * "Gerar termo de voluntariado" / "Novo termo" (desenho 06 de 25/10, `termoNovo`, l.1127): os campos do modal já
 * pré-preenchidos. NADA é gravado: o rascunho vive só na tela até o "Enviar para aceite". Quem: o interno que gere o
 * banco, num currículo que aceite voluntário. Havendo termo anterior, o rascunho é a cópia dele.
 */
final class GerarRascunhoDoTermoUseCase
{
    public function __construct(
        private readonly LocalizadorDeCurriculo $localizador,
        private readonly PoliticaDoTermo $politica,
        private readonly TermoDeVoluntariadoRepository $termos,
        private readonly RascunhoDoTermo $rascunho,
    ) {
    }

    /** @return array{campos: array<string, string>, versao: string, status: string} */
    public function executar(Contexto $ctx, string $codigoDoCurriculo): array
    {
        $cv = $this->localizador->localizar($ctx, $codigoDoCurriculo);
        if (!$this->politica->podeGerar($ctx, $cv)) {
            throw new AcaoNaoPermitida('Só a Central com acesso ao banco de talentos gera o termo de um currículo que aceita voluntário.');
        }

        return [
            'campos' => $this->rascunho->montar($ctx->configuracao->getSede(), $ctx->usuario, $cv, $this->termos->maisRecenteDo($cv), new \DateTimeImmutable()),
            'versao' => TermoDeVoluntariado::VERSAO_DO_MODELO,
            'status' => 'rascunho',
        ];
    }
}
