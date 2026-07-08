<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CriarObjetoInput;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Cadastra um Objeto de Cobrança dentro de uma Carteira do escritório (SPEC §4).
 *
 * História: um gestor cadastra o elemento ao qual a dívida se vincula (unidade, sala, veículo, imóvel,
 * contrato, matrícula...) numa carteira já existente. O conceito é genérico — a UI usa o rótulo da
 * carteira para nomeá-lo. A carteira é resolvida por id + tenant (guarda multi-tenant): só se cadastra
 * objeto em carteira do PRÓPRIO escritório — id inexistente ou de outro tenant é erro de entrada
 * (CarteiraNaoEncontradaException), tratado no controller. O objeto herda o tenant da carteira, o que
 * garante que objeto e carteira compartilhem sempre o mesmo escritório. Os campos textuais opcionais
 * (descrição, referência externa) são normalizados (trim; null quando vazio) para não gravar strings
 * em branco.
 */
final class CriarObjetoUseCase
{
    public function __construct(
        private readonly ObjetoCobrancaRepository $objetoRepository,
        private readonly CarteiraRepository $carteiraRepository,
    ) {
    }

    public function executar(CriarObjetoInput $input, Tenant $tenant, User $criadoPor): ObjetoCobranca
    {
        // Guarda multi-tenant: a carteira precisa ser do próprio escritório para receber o objeto.
        $carteira = $this->carteiraRepository->findOneByIdDoTenant((int) $input->carteiraId, $tenant);

        if ($carteira === null) {
            throw new CarteiraNaoEncontradaException((int) $input->carteiraId);
        }

        $objeto = new ObjetoCobranca();
        $objeto->setTenant($tenant);
        $objeto->setCarteira($carteira);
        $objeto->setIdentificacao(trim((string) $input->identificacao));
        $objeto->setDescricao($this->normalizar($input->descricao));
        $objeto->setReferenciaExterna($this->normalizar($input->referenciaExterna));
        $objeto->setCriadoPor($criadoPor);

        $this->objetoRepository->salvar($objeto, true);

        return $objeto;
    }

    private function normalizar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim($valor);

        return $valor !== '' ? $valor : null;
    }
}
