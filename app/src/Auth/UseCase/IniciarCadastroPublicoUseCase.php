<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\IniciarCadastroPublicoInput;
use App\Auth\Entity\CadastroPendente;
use App\Auth\Enum\StatusOab;
use App\Auth\Repository\CadastroPendenteRepository;
use App\Auth\Service\ValidadorOab;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Passo 1 do auto-cadastro público: valida e cria um CadastroPendente (sem criar
 * conta nem escritório ainda). A conta e o escritório só nascem na confirmação por
 * e-mail (ConfirmarCadastroUseCase). A senha já é hasheada aqui — o texto puro nunca
 * é persistido.
 *
 * A validação de FORMATO usa o ValidadorOab (ponto único — fecha a #4). A OAB é
 * verificada contra o CNA e o resultado fica gravado no pendente para ser copiado ao
 * User na confirmação (dormente por ora → `nao_verificada`).
 */
final class IniciarCadastroPublicoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly CadastroPendenteRepository $cadastroPendenteRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidadorOab $validadorOab,
    ) {}

    public function executar(IniciarCadastroPublicoInput $input, string $ip, ?string $userAgent): CadastroPendente
    {
        // Normaliza a caixa como os convites já fazem. Sem isso, `Ana@adv.com` nasce como
        // conta distinta de `ana@adv.com` (o índice único é btree comum, sem lower()) e
        // some de qualquer busca normalizada — inclusive da recuperação de senha.
        $email = mb_strtolower(trim($input->email));
        $oabUf = strtoupper($input->oabUf);

        // Checagem de duplicidade ignorando a caixa: senão o próprio auto-cadastro cria
        // a segunda conta que depois ninguém consegue distinguir.
        if ($this->userRepository->encontrarPorEmailIgnorandoCaixa($email) !== []) {
            throw new \DomainException('Já existe uma conta com este e-mail. Faça login para criar o escritório por dentro.');
        }

        // OAB é obrigatória no cadastro público (Passo 1; vira opcional só no Passo 3).
        if ($input->oabNumero === '' && $oabUf === '') {
            throw new \InvalidArgumentException('Informe a OAB (número e UF).');
        }

        $this->validadorOab->validarFormato($input->oabNumero, $oabUf);

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
            oabUf: $oabUf,
            senhaHash: $this->passwordHasher->hashPassword(new User(), $input->senha),
            ip: $ip,
            expiresAt: new \DateTimeImmutable('+24 hours'),
            userAgent: $userAgent,
        );

        // Verifica a OAB e guarda o status no pendente (copiado ao User na confirmação).
        $resultado = $this->validadorOab->verificar($input->oabNumero, $oabUf, trim($input->nomeCompleto));
        $cadastro->setOabStatus($resultado->status);
        $cadastro->setOabNomeOficial($resultado->nomeOficial);

        $this->em->persist($cadastro);
        $this->em->flush();

        return $cadastro;
    }
}
