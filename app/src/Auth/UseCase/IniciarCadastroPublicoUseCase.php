<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\IniciarCadastroPublicoInput;
use App\Auth\Entity\CadastroPendente;
use App\Auth\Repository\CadastroPendenteRepository;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Passo 1 do auto-cadastro público: valida e cria um CadastroPendente (sem criar
 * conta nem escritório ainda). A conta e o escritório só nascem na confirmação por
 * e-mail (ConfirmarCadastroUseCase). A senha já é hasheada aqui — o texto puro nunca
 * é persistido.
 */
final class IniciarCadastroPublicoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly CadastroPendenteRepository $cadastroPendenteRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function executar(IniciarCadastroPublicoInput $input, string $ip, ?string $userAgent): CadastroPendente
    {
        $email = trim($input->email);

        if ($this->userRepository->findOneBy(['email' => $email]) !== null) {
            throw new \DomainException('Já existe uma conta com este e-mail. Faça login para criar o escritório por dentro.');
        }

        $this->validarOab($input->oabNumero, $input->oabUf);

        // Limpa tentativas anteriores do mesmo e-mail (permite reiniciar o cadastro).
        foreach ($this->cadastroPendenteRepository->encontrarPorEmail($email) as $anterior) {
            $this->em->remove($anterior);
        }

        $cadastro = new CadastroPendente(
            email: $email,
            token: bin2hex(random_bytes(32)),
            nomeCompleto: trim($input->nomeCompleto),
            nomeEscritorio: trim($input->nomeEscritorio),
            oabNumero: $input->oabNumero,
            oabUf: strtoupper($input->oabUf),
            senhaHash: $this->passwordHasher->hashPassword(new User(), $input->senha),
            ip: $ip,
            expiresAt: new \DateTimeImmutable('+24 hours'),
            userAgent: $userAgent,
        );

        $this->em->persist($cadastro);
        $this->em->flush();

        return $cadastro;
    }

    private function validarOab(string $numero, string $uf): void
    {
        if (preg_match('/^\d+$/', $numero) !== 1) {
            throw new \InvalidArgumentException('Número da OAB deve conter apenas dígitos.');
        }

        if (preg_match('/^[A-Z]{2}$/', strtoupper($uf)) !== 1) {
            throw new \InvalidArgumentException('UF da OAB deve ter exatamente 2 letras.');
        }
    }
}
