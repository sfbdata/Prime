<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\GuardaDeWhatsapp;

/**
 * "Conectar WhatsApp": cadastra o contato que vai receber os avisos desta demanda.
 *
 * Quem: quem usa a aba WhatsApp (Master, Equipe, Solicitante). Pré-condição: o contato autorizou
 * receber as mensagens (a caixa de consentimento do desenho), e o celular tem DDD.
 * Pós-condição: o contato entra ativo, com todas as fases ligadas, e a mensagem de boas-vindas
 * fica pronta para enviar. Nada sai do BlueJus sem o clique em "Enviar pelo WhatsApp".
 */
final class ConectarWhatsappUseCase
{
    public function __construct(
        private readonly GuardaDeWhatsapp $guarda,
        private readonly DemandaExternaRepository $repositorio,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, string $nome, string $telefone, bool $autorizou): DemandaExterna
    {
        $nome = mb_substr(trim($nome), 0, 120);
        $digitos = preg_replace('/\D/', '', $telefone) ?? '';
        if (str_starts_with($digitos, '55') && strlen($digitos) > 11) {
            $digitos = substr($digitos, 2);
        }
        if ($nome === '' || strlen($digitos) < 10 || strlen($digitos) > 11) {
            throw new DadosInvalidos('Informe o nome e um celular com DDD.');
        }
        if (!$autorizou) {
            throw new DadosInvalidos('Confirme que o contato autorizou receber mensagens.');
        }

        [$demanda, $ctx] = $this->guarda->demanda($ctx, $codigo);
        $demanda->conectarWhatsapp($nome, $digitos, $ctx->autor());
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }
}
