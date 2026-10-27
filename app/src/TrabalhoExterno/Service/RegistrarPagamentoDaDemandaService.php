<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\AcaoDemanda;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Repository\ConfiguracaoCentralRepository;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;

/**
 * O miolo que muda a demanda quando o dinheiro chega: marco "Pago", registro "Pagamento de R$ x confirmado via PIX",
 * aviso de andamento e `salvar`. Os dois caminhos o usam: o botão "Confirmar recebimento" (pessoa, já com a demanda
 * travada) e a confirmação do banco ({@see registrarPeloBanco()}, que trava e confere sozinha).
 */
final class RegistrarPagamentoDaDemandaService
{
    public const AUTOR_DO_BANCO = 'Sistema (banco)';

    public function __construct(
        private readonly DemandaExternaRepository $repositorio,
        private readonly AvisosDoTrabalhoExterno $avisos,
        private readonly ConfiguracaoCentralRepository $configuracoes,
    ) {
    }

    /** Pelo botão: a demanda já está travada e conferida por quem chama. */
    public function registrar(DemandaExterna $demanda, Autor $autor, string $valorFormatado, Contexto $ctx): void
    {
        $demanda->confirmarRecebimentoDoPix($autor, $valorFormatado);
        $this->avisos->andamentoDaDemanda($ctx, $demanda);
        $this->repositorio->salvar($demanda);
    }

    /**
     * Pelo banco: trava a demanda, relê e só então age. `pagoEm` preenchido (o botão chegou antes) ou situação que
     * não aceita pagamento (cancelada, por exemplo) não mudam nada e devolvem `false`; quem chama deixa o pagamento
     * para o Master decidir. Nunca lança por regra dentro da transação.
     */
    public function registrarPeloBanco(DemandaExterna $demanda, string $valorFormatado): bool
    {
        return $this->repositorio->comADemandaTravada($demanda, function () use ($demanda, $valorFormatado): bool {
            if ($demanda->getPagoEm() !== null || !in_array($demanda->getSituacao(), AcaoDemanda::RegistrarPagamento->origens(), true)) {
                return false;
            }
            $demanda->confirmarRecebimentoDoPix(new Autor(null, self::AUTOR_DO_BANCO, Perfil::Master), $valorFormatado);
            $this->avisos->andamentoAutomaticoDaDemanda($this->configuracoes->atual(), $demanda);
            $this->repositorio->salvar($demanda);

            return true;
        });
    }
}
