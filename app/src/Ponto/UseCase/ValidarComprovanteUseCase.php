<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Ponto\DTO\DadosDoComprovante;
use App\Ponto\Repository\ComprovanteDoPontoRepository;
use App\Ponto\Service\CodigoDoComprovante;
use App\Ponto\Service\SeloDoComprovante;

/**
 * Validação do código do comprovante (E15, P50): a janela "Validar código" da /ponto e a página aberta para quem lê
 * o QR Code, sem login e sem escritório.
 *
 * Autêntico só quando o código existe E o HMAC confere com a chave da versão gravada E os dados selados trazem o mesmo
 * código. Qualquer outro caso (formato errado, código que não existe, selo que não confere, selo indisponível) devolve
 * null, sem dizer qual: quem chama responde "Código não localizado." do mesmo jeito para todos.
 */
final class ValidarComprovanteUseCase
{
    public function __construct(
        private readonly ComprovanteDoPontoRepository $comprovantes,
        private readonly SeloDoComprovante $selo,
    ) {
    }

    public function executar(string $codigoDigitado): ?DadosDoComprovante
    {
        $codigo = CodigoDoComprovante::normalizar($codigoDigitado);
        if (!$this->selo->disponivel() || !CodigoDoComprovante::temFormato($codigo)) {
            return null;
        }

        $comprovante = $this->comprovantes->porCodigo($codigo);
        if ($comprovante === null || !$this->selo->conferir($comprovante->getDadosAssinados(), $comprovante->getHmac(), $comprovante->getVersaoChave())) {
            return null;
        }

        return $this->dadosDoMesmoCodigo($comprovante->getDadosAssinados(), $codigo);
    }

    private function dadosDoMesmoCodigo(string $canonico, string $codigo): ?DadosDoComprovante
    {
        try {
            $dados = DadosDoComprovante::deCanonico($canonico);
        } catch (\UnexpectedValueException) {
            return null;
        }

        return $dados->codigo === $codigo ? $dados : null;
    }
}
