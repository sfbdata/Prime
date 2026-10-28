<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Tenant\Armazenamento\ChavesDeTenant;
use App\Tenant\Exception\AcessoAoEscritorioNegado;
use App\Tenant\Exception\DadosDoEscritorioInvalidos;
use App\Tenant\Service\GuardaDaEdicaoDoEscritorio;
use App\Tenant\Service\PreparadorDeLogoDoEscritorio;
use Psr\Clock\ClockInterface;

/**
 * "Enviar logo" / "Trocar logo" da tela Meu Escritório (spec meu-escritorio.md, D4). Só o primário ou o Master. A
 * imagem é conferida e regravada pelo {@see PreparadorDeLogoDoEscritorio}; o arquivo novo só sai se a transação
 * comprovadamente não confirmou (`TransacaoComArquivoNovo`) e o antigo só sai DEPOIS do COMMIT (INV-6).
 */
final class EnviarLogoDoEscritorioUseCase
{
    public function __construct(
        private readonly GuardaDaEdicaoDoEscritorio $guarda,
        private readonly PreparadorDeLogoDoEscritorio $preparador,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly RemocaoAposTransacao $remocao,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @throws AcessoAoEscritorioNegado
     * @throws DadosDoEscritorioInvalidos
     */
    public function executar(User $ator, Tenant $tenant, string $bytes): void
    {
        $this->guarda->exigir($ator, $tenant);
        $logo = $this->preparador->preparar($bytes);

        $antiga = ChavesDeTenant::logo($tenant);
        $armazenado = $this->armazenamento->gravar(ChavesDeTenant::novaLogo($tenant, $logo->extensao), FonteDeConteudo::deTexto($logo->bytes));
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());

        $this->transacao->executar(
            static function () use ($tenant, $armazenado, $ator, $agora): void {
                $tenant->setLogoChave($armazenado->chave->nome)->carimbarAlteracao($ator, $agora);
            },
            static fn (): array => [$armazenado->chave],
            'tenant_logo_enviar',
        );

        if ($antiga !== null) {
            $this->remocao->remover([$antiga], 'tenant_logo_trocada');
        }
    }
}
