<?php

declare(strict_types=1);

namespace App\Cliente\UseCase;

use App\Cliente\DTO\ClienteAchadoNaBusca;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\Repository\ClienteRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\EscopoDePastas;

/**
 * A busca de clientes do escritório por nome ou documento, sem sessão: a da tela da pasta (modal "Adicionar Cliente" e
 * busca do cartão Clientes) e a `ler_clientes` do conector (`buscar`).
 *
 * História: quem busca digita um trecho do nome ou o CPF ou CNPJ inteiro. O NOME casa por trecho; o DOCUMENTO só casa
 * quando os dígitos do termo são o documento inteiro (11 de CPF, 14 de CNPJ) e iguais ao gravado. Trecho de dígitos não
 * casa com documento: se casasse, buscas por pedaços ("456", "4567"...) confirmariam dígito a dígito o que a máscara
 * esconde. Documento casado vem antes do nome casado; no empate, a ordem do repositório.
 *
 * Só os clientes do escritório e, para a pessoa com acesso restrito, só os das pastas sob a responsabilidade dela
 * ({@see EscopoDePastas::idsDosClientesNoEscopo}). Termo com menos de {@see MINIMO_DE_CARACTERES} caracteres não busca.
 * Quem chama decide o resto: a permissão da porta, o limite e o que sai de cada achado.
 */
final class BuscarClientesUseCase
{
    public const MINIMO_DE_CARACTERES = 2;

    private const DOCUMENTO_CASADO = 2;
    private const NOME_CASADO = 1;

    public function __construct(
        private readonly ClienteRepository $clientes,
        private readonly EscopoDePastas $escopo,
    ) {
    }

    /** @return list<ClienteAchadoNaBusca> os achados, documento casado primeiro */
    public function executar(string $termo, User $quemBusca, Tenant $escritorio): array
    {
        $termo = trim($termo);
        if (mb_strlen($termo) < self::MINIMO_DE_CARACTERES) {
            return [];
        }
        $termoMinusculo = mb_strtolower($termo);
        $termoDigitos = (string) preg_replace('/\D/', '', $termo);
        $documentoDigitado = \in_array(\strlen($termoDigitos), [11, 14], true);
        $noEscopo = $this->escopo->idsDosClientesNoEscopo($quemBusca, $escritorio);

        $achados = [];
        foreach ($this->clientes->findBy(['tenant' => $escritorio]) as $cliente) {
            // Defesa em profundidade: a consulta já filtra o escritório, mas um cliente de outro aqui vazaria nome e documento.
            if ($cliente->getTenant()?->getId() !== $escritorio->getId() || ($noEscopo !== null && !\in_array($cliente->getId(), $noEscopo, true))) {
                continue;
            }
            $achado = self::casar($cliente, $termoMinusculo, $documentoDigitado ? $termoDigitos : null);
            if ($achado !== null) {
                $achados[] = $achado;
            }
        }
        // Ordenação estável: no empate, a ordem do repositório.
        usort($achados, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_map(static fn (array $achado): ClienteAchadoNaBusca => $achado[1], $achados);
    }

    /** @return array{int, ClienteAchadoNaBusca}|null os pontos e o achado; null quando nada casa */
    private static function casar(Cliente $cliente, string $termoMinusculo, ?string $documentoDigitado): ?array
    {
        $nome = match (true) {
            $cliente instanceof ClientePF => $cliente->getNomeCompleto(),
            $cliente instanceof ClientePJ => $cliente->getRazaoSocial(),
            default => '',
        };
        $documento = match (true) {
            $cliente instanceof ClientePF => $cliente->getCpf(),
            $cliente instanceof ClientePJ => $cliente->getCnpj(),
            default => '',
        };
        $digitos = (string) preg_replace('/\D/', '', $documento);
        $documentoInteiro = $documentoDigitado !== null && $digitos === $documentoDigitado;
        $pontos = match (true) {
            $documentoInteiro => self::DOCUMENTO_CASADO,
            str_contains(mb_strtolower($nome), $termoMinusculo) => self::NOME_CASADO,
            default => 0,
        };

        return $pontos === 0 ? null : [$pontos, new ClienteAchadoNaBusca($cliente, $nome, $documento, $digitos, $documentoInteiro)];
    }
}
