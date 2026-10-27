<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ModeloDePecaInput;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Enum\TipoDePeca;
use App\Pasta\Exception\ModeloDePecaInvalidoException;
use App\Pasta\Repository\ModeloDePecaRepository;
use App\Pasta\Service\SanitizadorDeModeloDePeca;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Cria ou edita um modelo de petição do banco de modelos do escritório.
 *
 * Duplicar também passa por aqui: a cópia só existe depois de "Salvar modelo" (desenho 02), então
 * é um modelo novo como outro qualquer. O corpo é sanitizado AQUI, nunca confiando no editor do
 * navegador. Na edição o autor original fica; quem editou não o substitui.
 */
final class SalvarModeloDePecaUseCase
{
    public function __construct(
        private readonly ModeloDePecaRepository $modeloRepository,
        private readonly SanitizadorDeModeloDePeca $sanitizador,
    ) {
    }

    public function executar(ModeloDePecaInput $input, Tenant $tenant, User $autor, ?ModeloDePeca $existente = null): ModeloDePeca
    {
        if ($existente !== null && $existente->getTenant() !== $tenant) {
            throw new AccessDeniedException('Modelo não pertence ao escritório.');
        }

        $titulo = trim($input->titulo);
        if ($titulo === '') {
            throw ModeloDePecaInvalidoException::semTitulo();
        }
        $materia = MateriaDeModelo::tryFrom($input->materia) ?? throw ModeloDePecaInvalidoException::materiaDesconhecida();
        $tipo    = TipoDePeca::tryFrom($input->tipo) ?? throw ModeloDePecaInvalidoException::tipoDesconhecido();

        $corpo = $this->sanitizador->sanitizar($input->corpo);
        if (SanitizadorDeModeloDePeca::estaVazio($corpo)) {
            throw ModeloDePecaInvalidoException::semTexto();
        }

        $modelo = $existente ?? (new ModeloDePeca($tenant))->setAutor($autor);
        $modelo->atualizar($titulo, $materia, $tipo, $corpo);

        $this->modeloRepository->salvar($modelo, flush: true);

        return $modelo;
    }
}
