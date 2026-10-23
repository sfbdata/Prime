<?php

declare(strict_types=1);

namespace App\Novidades\Twig;

use App\Novidades\Service\LeitorDeNovidades;
use Psr\Log\LoggerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `novidades_mais_recente()`: a versão mais nova com novidades, para o selo do menu do usuário.
 * O selo some quando a pessoa abre a página (o navegador guarda a última versão vista).
 *
 * Chamada em TODA página logada (menu do base.html.twig): um novidades.yaml ilegível não pode
 * derrubar o sistema. O erro vira log e o menu fica sem selo; quem pega o arquivo quebrado antes
 * da produção é o tests/Arquitetura/NovidadesArquivoTest, no portão do PR.
 */
final class NovidadesExtension extends AbstractExtension
{
    private ?string $memo = null;
    private bool $lido = false;

    public function __construct(
        private readonly LeitorDeNovidades $leitor,
        private readonly LoggerInterface $logger,
    ) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('novidades_mais_recente', $this->maisRecente(...))];
    }

    public function maisRecente(): ?string
    {
        if (!$this->lido) {
            $this->lido = true;
            try {
                $this->memo = $this->leitor->maisRecente()?->versao;
            } catch (\Throwable $e) {
                $this->logger->error('Novidades: config/novidades.yaml ilegível; menu sem selo.', ['exception' => $e]);
            }
        }

        return $this->memo;
    }
}
