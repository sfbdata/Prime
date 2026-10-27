<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Twig\DocumentoBrExtension;

/**
 * Os dados da pasta que preenchem as variáveis de um modelo de petição (spec
 * `modelos-pasta-desenho-02.md`, M2). O que a pasta não tem volta vazio e o {@see PreenchedorDeModelo}
 * o troca por `___`.
 *
 * Fontes: cliente principal (nome e CPF; CPF só existe em pessoa física), processo principal, NUP da
 * pasta, cidade do escritório (o processo não guarda comarca), data de hoje por extenso, e o advogado:
 * o responsável da pasta ou, sem ele, quem está usando o modelo. A OAB é "UF número" do advogado.
 */
final class ContextoDaPecaDaPasta
{
    private const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    /** @return array<string, string> chave => valor, com as 8 variáveis conhecidas */
    public function montar(Pasta $pasta, User $usuario, \DateTimeImmutable $agora): array
    {
        $principal = $pasta->getClientePrincipal();
        $advogado  = $pasta->getResponsavel() ?? $usuario;

        return [
            'cliente'  => $principal !== null ? $principal->getNomeExibicao() : (string) $pasta->getNomeCliente(),
            'cpf'      => $principal instanceof ClientePF ? (new DocumentoBrExtension())->documentoBr($principal->getCpf()) : '',
            'processo' => (string) $pasta->getProcessoPrincipal()?->getNumeroProcesso(),
            'pasta'    => (string) $pasta->getNup(),
            'cidade'   => (string) $pasta->getTenant()?->getCidade(),
            'data'     => $this->porExtenso($agora),
            'advogado' => (string) $advogado->getFullName(),
            'oab'      => trim((string) $advogado->getOabUf() . ' ' . (string) $advogado->getOabNumero()),
        ];
    }

    private function porExtenso(\DateTimeImmutable $data): string
    {
        return $data->format('j') . ' de ' . self::MESES[(int) $data->format('n') - 1] . ' de ' . $data->format('Y');
    }
}
