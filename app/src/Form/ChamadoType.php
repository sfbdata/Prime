<?php

namespace App\Form;

use App\Entity\Auth\User;
use App\Entity\ServiceDesk\Chamado;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\File;

class ChamadoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];
        $isAdmin = $options['is_admin'];

        $builder
            ->add('titulo', TextType::class, [
                'label' => 'Título',
                'attr' => [
                    'placeholder' => 'Descreva brevemente o problema',
                    'maxlength' => 255,
                ],
            ])
            ->add('descricao', TextareaType::class, [
                'label' => 'Descrição',
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'Descreva detalhadamente o problema ou solicitação...',
                ],
            ])
            ->add('categoria', ChoiceType::class, [
                'label' => 'Categoria',
                'choices' => [
                    'Software' => Chamado::CATEGORIA_SOFTWARE,
                    'Hardware' => Chamado::CATEGORIA_HARDWARE,
                    'Impressora' => Chamado::CATEGORIA_IMPRESSORA,
                    'Rede/Internet' => Chamado::CATEGORIA_REDE,
                    'Acesso/Permissões' => Chamado::CATEGORIA_ACESSO,
                    'E-mail' => Chamado::CATEGORIA_EMAIL,
                    'Outros' => Chamado::CATEGORIA_OUTROS,
                ],
                'placeholder' => 'Selecione uma categoria',
            ])
            ->add('prioridade', ChoiceType::class, [
                'label' => 'Prioridade',
                'choices' => [
                    'Baixa' => Chamado::PRIORIDADE_BAIXA,
                    'Média' => Chamado::PRIORIDADE_MEDIA,
                    'Alta' => Chamado::PRIORIDADE_ALTA,
                    'Crítica' => Chamado::PRIORIDADE_CRITICA,
                ],
            ])
            ->add('departamento', TextType::class, [
                'label' => 'Departamento',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Ex: Financeiro, RH, Jurídico...',
                ],
            ])
            ->add('anexos', FileType::class, [
                'label' => 'Anexos',
                'multiple' => true,
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'accept' => '.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.zip,.txt',
                ],
                'constraints' => [
                    new All([
                        new File([
                            'maxSize' => '10M',
                            'mimeTypes' => [
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'image/jpeg',
                                'image/png',
                                'image/gif',
                                'application/zip',
                                'text/plain',
                            ],
                            'mimeTypesMessage' => 'Arquivo não permitido. Tipos aceitos: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, GIF, ZIP, TXT',
                        ])
                    ])
                ],
            ]);

        // Campos administrativos (apenas para equipe de TI)
        if ($isAdmin) {
            $builder
                ->add('status', ChoiceType::class, [
                    'label' => 'Status',
                    'choices' => [
                        'Aberto' => Chamado::STATUS_ABERTO,
                        'Em Andamento' => Chamado::STATUS_EM_ANDAMENTO,
                        'Resolvido' => Chamado::STATUS_RESOLVIDO,
                        'Fechado' => Chamado::STATUS_FECHADO,
                    ],
                ])
                ->add('responsavel', EntityType::class, [
                    'class' => User::class,
                    'choice_label' => 'fullName',
                    'label' => 'Responsável',
                    'required' => false,
                    'placeholder' => 'Não atribuído',
                    'query_builder' => function (UserRepository $repo) {
                        return $repo->createQueryBuilder('u')
                            ->where('u.isActive = :active')
                            ->setParameter('active', true)
                            ->orderBy('u.fullName', 'ASC');
                    },
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Chamado::class,
            'is_edit' => false,
            'is_admin' => false,
        ]);

        $resolver->setAllowedTypes('is_edit', 'bool');
        $resolver->setAllowedTypes('is_admin', 'bool');
    }
}
