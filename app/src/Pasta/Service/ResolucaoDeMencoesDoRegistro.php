<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Repository\NotificacaoRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A parte das @menções do Registro que depende do banco — usada IGUAL no envio
 * (`EnviarMensagemPastaUseCase`) e na edição (`EditarMensagemPastaUseCase`), para que nenhum dos
 * dois caminhos grave um token que o outro recusaria.
 *
 * `resolver()` — confere cada id dos tokens contra os colegas com vínculo ATIVO neste escritório
 * (e usuário ativo), numa consulta só presa ao tenant. Id válido: rótulo trocado pelo nome do
 * banco. Id inexistente, de outro escritório, de ex-colaborador ou além do teto
 * ({@see MencoesDoRegistro::MAXIMO_POR_REGISTRO}): a marcação cai e fica `@rótulo` em texto comum.
 *
 * `notificar()` — `TIPO_PASTA_MENCAO_REGISTRO` para cada mencionado válido: "<Primeiro nome>
 * mencionou você na Pasta N · Dados da pasta" (desenho `menNotificar`), prévia de 180, link
 * `#pasta-msg-<id>`. Nunca o autor nem quem `$pular` indicar (precedência da resposta); só quem
 * tem acesso de leitura a ESTA pasta; anti-duplicata por destinatário + escritório + tipo + link —
 * o link é o da mensagem, então quem já foi avisado por ela (no envio ou numa edição anterior)
 * não recebe de novo.
 */
final class ResolucaoDeMencoesDoRegistro
{
    /** Onde o Registro mora na pasta_show — rótulo do desenho (`abaRot = 'Dados da pasta'`). */
    public const ABA_DO_REGISTRO = 'Dados da pasta';

    /** Prévia da menção no sino — `menNotificar`: `prev.slice(0, 180) + '…'`. */
    private const LIMITE_PREVIA = 180;

    public function __construct(
        private readonly MencoesDoRegistro $mencoes,
        private readonly UserRepository $userRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly NotificacaoRepository $notificacaoRepository,
        private readonly NotificacaoService $notificacaoService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Recebe conteúdo JÁ sanitizado.
     *
     * @return array{0: string, 1: list<User>} o conteúdo na forma canônica e os mencionados válidos
     *                                         (na ordem em que aparecem)
     */
    public function resolver(string $conteudo, Tenant $tenant): array
    {
        $ids = $this->mencoes->extrairIds($conteudo);
        if ($ids === []) {
            return [$conteudo, []];
        }

        $validos = [];
        $nomes   = [];
        foreach ($this->userRepository->findColaboradoresAtivosPorTenant($tenant) as $colega) {
            $id = $colega->getId();
            if ($id === null || !in_array($id, $ids, true) || !$colega->isActive()) {
                continue;
            }
            $validos[$id] = $colega;
            $nomes[$id]   = (string) $colega->getFullName();
        }

        $mencionados = [];
        foreach ($ids as $id) {
            if (isset($validos[$id])) {
                $mencionados[] = $validos[$id];
            }
        }

        return [$this->mencoes->reescrever($conteudo, $nomes), $mencionados];
    }

    /**
     * Chamar DEPOIS do flush (o link leva o id da mensagem) e dentro da transação da mensagem.
     *
     * @param list<User> $mencionados saída de {@see resolver()}
     * @param User|null  $pular       quem já recebe aviso mais específico por esta mensagem (o autor
     *                                do comentário respondido)
     */
    public function notificar(array $mencionados, PastaMensagem $mensagem, Pasta $pasta, User $autor, Tenant $tenant, ?User $pular = null): void
    {
        $pastaId = $pasta->getId();
        if ($mencionados === [] || $pastaId === null || $mensagem->getId() === null) {
            return;
        }

        $url    = $this->urlGenerator->generate('pasta_show', ['id' => $pastaId]) . '#pasta-msg-' . $mensagem->getId();
        $titulo = sprintf(
            '%s mencionou você na Pasta %s · %s',
            self::primeiroNome($autor),
            ($nup = $pasta->getNup()) !== null && $nup !== '' ? $nup : '#' . $pastaId,
            self::ABA_DO_REGISTRO,
        );
        $previa = $this->mencoes->textoDoSino($mensagem->getConteudo());
        if (mb_strlen($previa) > self::LIMITE_PREVIA) {
            $previa = mb_substr($previa, 0, self::LIMITE_PREVIA) . '…';
        }

        foreach ($mencionados as $pessoa) {
            if (self::mesmoUsuario($pessoa, $autor) || ($pular !== null && self::mesmoUsuario($pessoa, $pular))) {
                continue;
            }

            if (!$this->permissionChecker->canAccessResource($pessoa, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW)) {
                continue;
            }

            $jaExiste = $this->notificacaoRepository->findOneBy([
                'usuario' => $pessoa,
                'tenant'  => $tenant,
                'tipo'    => Notificacao::TIPO_PASTA_MENCAO_REGISTRO,
                'url'     => $url,
            ]);
            if ($jaExiste !== null) {
                continue;
            }

            $this->notificacaoService
                ->criar($pessoa, $tenant, Notificacao::TIPO_PASTA_MENCAO_REGISTRO, $titulo, $previa)
                ->setUrl($url);
        }
    }

    private static function primeiroNome(User $usuario): string
    {
        $nome = trim((string) $usuario->getFullName());

        return $nome === '' ? 'Alguém' : explode(' ', $nome)[0];
    }

    private static function mesmoUsuario(User $a, User $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }
}
