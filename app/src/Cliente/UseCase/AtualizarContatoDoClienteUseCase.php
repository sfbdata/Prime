<?php

declare(strict_types=1);

namespace App\Cliente\UseCase;

use App\Cliente\DTO\AtualizarContatoDoClienteInput;
use App\Cliente\Entity\Cliente;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\ContatoInvalidoException;
use App\Cliente\Repository\ClienteRepository;
use App\Entity\Tenant\Tenant;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Troca UM contato do cliente pela janela "Detalhes do cliente" da tela da pasta
 * (desenho 1.2.3, dc L.810-836 e lógica L.5748-5786).
 *
 * História: quem atende o cliente vê, na janela aberta a partir da pasta, que o
 * celular mudou ou que falta o telefone fixo; clica no lápis (ou em "+ Telefone"),
 * digita e dá Enter — sem sair da pasta para a ficha completa.
 *
 * O modelo tem TRÊS slots fixos (`email` não nulo, `telefoneCelular`,
 * `telefoneFixo`); a lista livre do desenho não cabe nele, e este UseCase não a
 * inventa: só troca o valor de um slot existente.
 *
 * Regras:
 *  - o cliente tem de ser do escritório atual (outro escritório = não encontrado);
 *  - telefone: com DDD, 10 ou 11 dígitos; grava com a máscara do desenho,
 *    `(DD) 9XXXX-XXXX` / `(DD) XXXX-XXXX`. Vazio REMOVE (vira nulo);
 *  - e-mail: obrigatório no cadastro (coluna não nula, campo obrigatório na
 *    ficha) — não se remove, só se troca; validado e gravado em minúsculas;
 *  - a permissão (ACTION_EDIT) é do controller, que conhece o usuário.
 */
final class AtualizarContatoDoClienteUseCase
{
    public const CAMPO_CELULAR = 'celular';
    public const CAMPO_FIXO    = 'fixo';
    public const CAMPO_EMAIL   = 'email';

    public const CAMPOS = [self::CAMPO_CELULAR, self::CAMPO_FIXO, self::CAMPO_EMAIL];

    public function __construct(
        private readonly ClienteRepository $clienteRepository,
        private readonly ValidatorInterface $validator,
    ) {}

    public function executar(AtualizarContatoDoClienteInput $input, Tenant $tenant): void
    {
        $cliente = $this->clienteRepository->find($input->clienteId);
        if (!$cliente instanceof Cliente || $cliente->getTenant()?->getId() !== $tenant->getId()) {
            throw new ClienteNaoEncontradoException($input->clienteId);
        }

        $valor = trim($input->valor);

        match ($input->campo) {
            self::CAMPO_CELULAR => $cliente->setTelefoneCelular($this->telefone($valor)),
            self::CAMPO_FIXO    => $cliente->setTelefoneFixo($this->telefone($valor)),
            self::CAMPO_EMAIL   => $cliente->setEmail($this->email($valor)),
            default             => throw new ContatoInvalidoException('Contato desconhecido.'),
        };

        $this->clienteRepository->save($cliente, true);
    }

    /** Vazio = remover. Senão, só dígitos com DDD, gravados com a máscara do desenho. */
    private function telefone(string $valor): ?string
    {
        if ($valor === '') {
            return null;
        }

        $digitos = (string) preg_replace('/\D/', '', $valor);
        $total   = \strlen($digitos);
        if ($total !== 10 && $total !== 11) {
            throw new ContatoInvalidoException('Informe o telefone com DDD (10 ou 11 dígitos).');
        }

        $meio = $total === 11 ? 5 : 4;

        return sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, $meio), substr($digitos, 2 + $meio));
    }

    private function email(string $valor): string
    {
        if ($valor === '') {
            throw new ContatoInvalidoException('O e-mail é obrigatório no cadastro: troque-o em vez de remover.');
        }

        $violacoes = $this->validator->validate($valor, [
            new Assert\Email(mode: Assert\Email::VALIDATION_MODE_HTML5),
            new Assert\Length(max: 255),
        ]);
        if (\count($violacoes) > 0) {
            throw new ContatoInvalidoException('E-mail inválido.');
        }

        return mb_strtolower($valor);
    }
}
