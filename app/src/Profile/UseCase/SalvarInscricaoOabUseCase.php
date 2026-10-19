<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Auth\Service\EspelhoDaOabPrincipal;
use App\Entity\Auth\User;
use App\Profile\DTO\InscricaoOabInput;

/**
 * Criar ou editar uma inscrição na OAB da própria pessoa (desenho "07 - Meu Perfil", VS 18.10.26,
 * `_salvarOab`; spec docs/specs/meu-perfil-oab-inscricoes.md §1).
 *
 * Regras: número só com dígitos (o desenho aceita uma letra final, mas o banco exige só dígitos no espelho
 * User.oab_numero, chk_user_oab_numero); UF de uma das 27 seccionais; no máximo uma
 * inscrição por UF; sempre exatamente uma principal (a primeira nasce principal; escolher outra rebaixa a
 * atual a suplementar; não dá para deixar a pessoa sem principal). Inscrição nova ou com número/UF
 * mudados volta a Pendente. A principal é copiada para User.oab* (espelho). Uma transação, um flush.
 *
 * @throws \DomainException com a mensagem do desenho
 */
final class SalvarInscricaoOabUseCase
{
    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR',
        'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public function __construct(
        private readonly InscricaoOabRepository $inscricoes,
        private readonly EspelhoDaOabPrincipal $espelho,
    ) {
    }

    /** @return bool true quando a inscrição ficou Pendente (nova ou com número/UF mudados) */
    public function executar(User $usuario, InscricaoOabInput $input): bool
    {
        [$numero, $uf] = $this->exigirFormato($input);
        $todas         = $this->espelho->inscricoesDe($usuario);
        $alvo          = $this->localizar($todas, $input->id);
        $this->exigirUmaPorUf($todas, $alvo, $uf);

        $pendente       = $alvo === null || $alvo->getNumero() !== $numero || $alvo->getUf() !== $uf;
        $primeiraDaPessoa = $todas === [];
        if ($alvo === null) {
            $alvo    = new InscricaoOab($usuario, $numero, $uf, $todas === [] ? TipoInscricaoOab::Principal : $input->tipo);
            $todas[] = $alvo;
        } else {
            $alvo->alterar($numero, $uf);
        }
        $this->aplicarTipo($todas, $alvo, $primeiraDaPessoa ? TipoInscricaoOab::Principal : $input->tipo);

        $this->espelho->principalParaUsuario($usuario, $todas);
        $this->inscricoes->salvar($alvo, flush: true);

        return $pendente;
    }

    /** @return array{0: string, 1: string} */
    private function exigirFormato(InscricaoOabInput $input): array
    {
        // Pontos, traços, barras e espaços saem; letra ou outro caractere recusa (não some calado).
        $numero = preg_replace('/[\s.\-\/]/', '', $input->numero) ?? '';
        $uf     = strtoupper(trim($input->uf));
        if (preg_match('/^\d{1,10}$/', $numero) !== 1) {
            throw new \DomainException('Informe o número da OAB (somente números).');
        }
        if (!in_array($uf, self::UFS, true)) {
            throw new \DomainException('Escolha a UF da seccional.');
        }

        return [$numero, $uf];
    }

    /** @param list<InscricaoOab> $todas */
    private function localizar(array $todas, ?int $id): ?InscricaoOab
    {
        if ($id === null) {
            return null;
        }
        foreach ($todas as $inscricao) {
            if ($inscricao->getId() === $id) {
                return $inscricao;
            }
        }

        throw new \DomainException('Inscrição não encontrada.');
    }

    /** @param list<InscricaoOab> $todas */
    private function exigirUmaPorUf(array $todas, ?InscricaoOab $alvo, string $uf): void
    {
        foreach ($todas as $inscricao) {
            if ($inscricao !== $alvo && $inscricao->getUf() === $uf) {
                throw new \DomainException('Já existe uma inscrição na OAB/' . $uf . '. Cada seccional tem uma só inscrição por advogado.');
            }
        }
    }

    /** @param list<InscricaoOab> $todas */
    private function aplicarTipo(array $todas, InscricaoOab $alvo, TipoInscricaoOab $tipo): void
    {
        if ($tipo === TipoInscricaoOab::Principal) {
            foreach ($todas as $inscricao) {
                $inscricao === $alvo ? $inscricao->tornarPrincipal() : $inscricao->tornarSuplementar();
            }

            return;
        }
        $alvo->tornarSuplementar();
        foreach ($todas as $inscricao) {
            if ($inscricao->ehPrincipal()) {
                return;
            }
        }

        throw new \DomainException('É preciso ter uma inscrição principal.');
    }
}
