<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\PreferenciaDoUsuario;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;
use App\TrabalhoExterno\Service\Contexto;

/**
 * "Avisar quando sair vaga da minha área" / "Avisos de vagas ligados" (desenho 06 l.91 e 1107, spec
 * VS 18.10.26 §4.7). Quem: o externo (Solicitante ou Convidado), só com o `sino` ligado pelo Master e
 * nunca pela prévia. A escolha é da pessoa e guarda o escritório do contexto, onde o aviso chega
 * (`AvisosDoTrabalhoExterno::vagaNaArea`). Devolve o estado e o toast do desenho.
 */
final class DefinirAlertaDeVagasUseCase
{
    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $preferencias,
    ) {
    }

    /** @return array{alerta: array{disponivel: bool, ligado: bool}, toast: array{titulo: string, texto: string}} */
    public function executar(Contexto $ctx, bool $ligado): array
    {
        $this->exigirExternoComSino($ctx);
        $agora = new \DateTimeImmutable();
        $alerta = $this->preferencias->obter($ctx->usuario, PreferenciaDoUsuario::ALERTA_DE_VAGAS);
        $alerta->definir(['ligado' => $ligado, 'escritorioId' => $ctx->tenant?->getId()], $agora);
        $this->preferencias->salvar($alerta, flush: true);

        return [
            'alerta' => ['disponivel' => true, 'ligado' => $ligado],
            'toast' => $ligado
                ? ['titulo' => 'Avisos ligados', 'texto' => 'Você recebe um aviso assim que sair vaga ou oferta na sua área.']
                : ['titulo' => 'Avisos desligados', 'texto' => ''],
        ];
    }

    private function exigirExternoComSino(Contexto $ctx): void
    {
        if ($ctx->previa) {
            throw new AcaoNaoPermitida('Na pré-visualização não é possível alterar nada.');
        }
        if ($ctx->ehInterno()) {
            throw new AcaoNaoPermitida('Os avisos de vaga são para os profissionais externos.');
        }
        if (!$ctx->configuracao->getVagas()['sino']) {
            throw new AcaoNaoPermitida('Os avisos de vaga estão desligados pela Central.');
        }
    }
}
