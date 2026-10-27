<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Entity\ModeloDePecaUso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\ModeloDePecaRepository;
use App\Pasta\Repository\ModeloDePecaUsoRepository;
use App\Pasta\Service\PreenchedorDeModelo;
use App\Pasta\Service\SanitizadorDeModeloDePeca;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * "Usar na pasta N" do desenho 02: preenche o modelo com os dados da pasta (e o que a pessoa ajustou) e
 * guarda a minuta como documento da pasta, categoria PECA, pelo mesmo caminho do Peticionar. Conta o uso
 * (alimenta o "Mais usados") e registra quem usou, em qual pasta e qual documento nasceu.
 */
final class UsarModeloNaPastaUseCase
{
    private const LIMITE_DO_TITULO = 240;

    public function __construct(
        private readonly PreenchedorDeModelo $preenchedor,
        private readonly SanitizadorDeModeloDePeca $sanitizador,
        private readonly SalvarPecaTextoUseCase $salvarPeca,
        private readonly ModeloDePecaRepository $modeloRepository,
        private readonly ModeloDePecaUsoRepository $usoRepository,
    ) {
    }

    /** @param array<string, string> $valores chave => valor digitado em "DADOS DA PEÇA" */
    public function executar(ModeloDePeca $modelo, Pasta $pasta, Tenant $tenant, User $usuario, array $valores): PastaDocumento
    {
        if ($modelo->getTenant() !== $tenant || $pasta->getTenant() !== $tenant) {
            throw new AccessDeniedException('Modelo ou pasta não pertence ao escritório.');
        }

        $html   = $this->sanitizador->sanitizar($this->preenchedor->preencher($modelo->getCorpo(), $valores));
        $titulo = mb_substr($modelo->getTitulo(), 0, self::LIMITE_DO_TITULO) . ' · Pasta ' . $pasta->getNup();

        $documento = $this->salvarPeca->executar($pasta, null, $html, $titulo, PastaDocumento::CATEGORIA_PECA, $tenant, $usuario);

        $modelo->registrarUso();
        $this->modeloRepository->salvar($modelo);
        $this->usoRepository->salvar(new ModeloDePecaUso($tenant, $modelo, $pasta, $usuario, $documento), flush: true);

        return $documento;
    }
}
