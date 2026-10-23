<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Os avisos da cobrança do checklist, no sino e na Central (o desenho só usa a Central: sem e-mail nem
 * WhatsApp). Dois tipos, mapeados em `TiposDeAvisoDaCentral`:
 *  - cobrança de documento (grupo urgente, como o `cobranca` do desenho);
 *  - providência (checklist desativado e lembrete de revisar, o `conformidade` do desenho).
 *
 * Título e texto separados (o `NotificacaoService::criarNotificacao` repete o título no texto). Só persiste:
 * quem chama dá o flush, junto com o registro da cobrança.
 */
final class AvisoDoChecklist
{
    public const TIPO_COBRANCA = 'checklist_cobranca';
    public const TIPO_PROVIDENCIA = 'checklist_providencia';
    private const TAMANHO_DO_TITULO = 255;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param array{string, string} $tituloETexto */
    public function avisar(User $para, Tenant $escritorio, string $tipo, array $tituloETexto, ?string $url): void
    {
        [$titulo, $texto] = $tituloETexto;
        $aviso = new Notificacao();
        $aviso->setUsuario($para);
        $aviso->setTenant($escritorio);
        $aviso->setTipo($tipo);
        $aviso->setTitulo(mb_strlen($titulo) <= self::TAMANHO_DO_TITULO ? $titulo : rtrim(mb_substr($titulo, 0, self::TAMANHO_DO_TITULO - 3)) . '...');
        $aviso->setMensagem($texto);
        $aviso->setUrl($url);
        $this->em->persist($aviso);
    }

    /**
     * @param list<User>    $pessoas
     * @param array{string, string} $tituloETexto
     */
    public function avisarVarias(array $pessoas, ?User $exceto, Tenant $escritorio, string $tipo, array $tituloETexto, ?string $url): void
    {
        foreach ($pessoas as $pessoa) {
            if ($exceto === null || $pessoa->getId() !== $exceto->getId()) {
                $this->avisar($pessoa, $escritorio, $tipo, $tituloETexto, $url);
            }
        }
    }
}
