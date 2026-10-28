<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use App\Inteligencia\Service\NeutralizadorDeConteudo;

/**
 * O que passa pelo escritório antes de ir ao provedor, pela preferência de mascaramento dele (padrão: mascarar):
 * - {@see livre()}: o que a PESSOA escreveu (a pergunta e as falas do histórico). É instrução legítima, então a
 *   formatação (linhas, Markdown) fica; saem só caracteres de controle e invisíveis;
 * - {@see comoDado()}: o que NÃO nasceu no código (texto de arquivo anexado). Cada linha é neutralizada (tag
 *   delimitadora vira texto inerte) e mascarada, preservando as quebras de linha.
 */
final class ProtecaoDoTextoDoChat
{
    /** Controle (menos \t \n), zero-width e bidi: o que esconde instrução. */
    private const INVISIVEIS = '/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';

    public function __construct(
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly MascaradorDeDadosPessoais $mascarador,
    ) {
    }

    public function livre(Tenant $tenant, string $texto): string
    {
        $texto = (string) mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
        $texto = str_replace("\r\n", "\n", (string) preg_replace(self::INVISIVEIS, '', $texto));

        return trim($this->mascararSePreciso($tenant, $texto));
    }

    public function comoDado(Tenant $tenant, string $texto): string
    {
        $linhas = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $texto)) as $linha) {
            $linhas[] = NeutralizadorDeConteudo::neutralizar($linha);
        }

        return trim($this->mascararSePreciso($tenant, implode("\n", $linhas)));
    }

    /** Neutraliza um valor curto de uma linha (nome de arquivo, nome da pessoa). */
    public function rotulo(Tenant $tenant, string $texto, string $padrao): string
    {
        $valor = $this->mascararSePreciso($tenant, NeutralizadorDeConteudo::neutralizar($texto));

        return $valor === '' ? $padrao : $valor;
    }

    private function mascararSePreciso(Tenant $tenant, string $texto): string
    {
        $mascarar = $this->configuracoes->findDoTenant($tenant)?->isMascararDadosPessoais() ?? true;

        return $mascarar ? $this->mascarador->mascarar($texto) : $texto;
    }
}
