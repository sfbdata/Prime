<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CadastroDaPastaInput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaReu;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Repository\PastaReuRepository;
use App\Pasta\Service\DadosDaCobrancaNaPasta;
use App\Service\PermissionChecker;
use App\Shared\Service\DigitoVerificador;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Cadastro da pasta" da Controladoria (desenho 02 de 31/10/2026; spec central-documentos-controladoria, fatia B).
 *
 * História: a advogada abre a Controladoria, vai em Cadastro da pasta, informa o réu e o CPF, deixa a qualificação em
 * branco e salva. Salva mesmo assim: o cadastro fica "com pendência" até ter CPF e qualificação. E-mail e endereço nunca
 * seguram o salvamento. Quem pode EDITAR a pasta.
 *
 * Réu: é o principal da pasta (o primeiro da ordem; sem nenhum, nasce um). Contrato, unidade, titular do extrato e a
 * data-limite de prescrição são da pasta. Unidade e titular iguais ao que a Cobrança já sabe não são gravados (a
 * sugestão da Cobrança continua valendo se ela mudar); só o que a advogada de fato corrigiu fica na pasta.
 *
 * @return bool true quando o cadastro do réu está completo (CPF e qualificação)
 */
final class SalvarCadastroDaPastaUseCase
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly PastaReuRepository $reus,
        private readonly DadosDaCobrancaNaPasta $cobranca,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Pasta $pasta, User $usuario, Tenant $tenant, CadastroDaPastaInput $dados): bool
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }

        $documento = $this->documento($dados->cpf);
        $email = $this->texto($dados->email, 255, 'O e-mail');
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException('E-mail inválido. Confira o endereço.');
        }
        $prescricao = $this->prescricao($dados->prescricao);
        $nomeDoReu = PastaReu::normalizarNome($dados->reu);
        $campos = [$this->texto($dados->qualificacao, 500, 'A qualificação'), $email, $this->texto($dados->endereco, 500, 'O endereço')];

        $reu = $this->gravarReu($pasta, $tenant, $nomeDoReu, $documento, $campos);
        $this->gravarPasta($pasta, $tenant, $dados, $prescricao);
        $this->em->flush();

        return $reu?->estaCompleto() ?? false;
    }

    /** @param array{0: ?string, 1: ?string, 2: ?string} $campos qualificação, e-mail, endereço */
    private function gravarReu(Pasta $pasta, Tenant $tenant, string $nome, ?string $documento, array $campos): ?PastaReu
    {
        $reu = $this->reus->daPasta($pasta, $tenant)[0] ?? null;
        if ($nome === '') {
            if ($documento !== null || array_filter($campos) !== []) {
                throw new \DomainException('Informe o nome do réu para salvar o CPF, a qualificação, o e-mail ou o endereço.');
            }

            return $reu;
        }
        if (mb_strlen($nome) > 255) {
            throw new \DomainException('O nome do réu passa de 255 caracteres.');
        }
        if ($reu === null) {
            $reu = new PastaReu($tenant, $pasta, $nome, $documento, 0);
            $this->em->persist($reu);
        }
        $reu->setNome($nome);
        $reu->setDocumento($documento);
        $reu->completar(...$campos);

        return $reu;
    }

    private function gravarPasta(Pasta $pasta, Tenant $tenant, CadastroDaPastaInput $dados, ?\DateTimeImmutable $prescricao): void
    {
        $daCobranca = $this->cobranca->da($pasta, $tenant);
        $unidade = $this->texto($dados->unidade, 120, 'A unidade');
        $titular = $this->texto($dados->titularExtrato, 255, 'O titular do extrato');

        $pasta->setContrato($this->texto($dados->contrato, 120, 'O contrato'));
        $pasta->setUnidade($unidade === $daCobranca['unidade'] ? null : $unidade);
        $pasta->setTitularExtrato($titular === $daCobranca['titular'] ? null : $titular);
        $pasta->setPrescricaoEm($prescricao);
    }

    private function documento(string $cpf): ?string
    {
        $digitos = DigitoVerificador::digitos($cpf);
        if ($digitos === '') {
            return null;
        }
        $valido = match (strlen($digitos)) {
            11 => DigitoVerificador::cpfValido($digitos),
            14 => DigitoVerificador::cnpjValido($digitos),
            default => throw new \DomainException('CPF com 11 ou CNPJ com 14 dígitos'),
        };
        if (!$valido) {
            throw new \DomainException((strlen($digitos) === 11 ? 'CPF' : 'CNPJ') . ' inválido. Confira os números.');
        }

        return $digitos;
    }

    private function prescricao(string $valor): ?\DateTimeImmutable
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            throw new \DomainException('Data-limite de prescrição inválida.');
        }

        return $data;
    }

    private function texto(string $valor, int $maximo, string $rotulo): ?string
    {
        $valor = trim((string) preg_replace('/\s+/u', ' ', $valor));
        if (mb_strlen($valor) > $maximo) {
            throw new \DomainException($rotulo . ' passa de ' . $maximo . ' caracteres.');
        }
        if (preg_match('/[\p{Cc}<>]/u', $valor) === 1) {
            throw new \DomainException($rotulo . ' tem caracteres inválidos.');
        }

        return $valor === '' ? null : $valor;
    }
}
