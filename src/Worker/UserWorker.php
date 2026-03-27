<?php

declare(strict_types=1);

namespace PsychedCms\DataExchange\Worker;

use Doctrine\ORM\EntityManagerInterface;
use PsychedCms\DataExchange\Import\ReferenceResolver;

final class UserWorker
{
    /**
     * @param class-string $userClass
     */
    public function __construct(
        private readonly string $userClass,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(object $user): array
    {
        $data = [
            'username' => $user->getUsername(),
        ];

        if (method_exists($user, 'getEmail')) {
            $data['email'] = $user->getEmail();
        }

        if (method_exists($user, 'getRoles')) {
            $data['roles'] = $user->getRoles();
        }

        if (method_exists($user, 'getLocale')) {
            $data['locale'] = $user->getLocale();
        }

        if (method_exists($user, 'getAvatar')) {
            $avatar = $user->getAvatar();

            if ($avatar !== null && \is_array($avatar)) {
                $data['avatar'] = array_filter([
                    'storagePath' => $avatar['storagePath'] ?? null,
                    'alt' => $avatar['alt'] ?? null,
                    'mimeType' => $avatar['mimeType'] ?? null,
                ], fn ($v) => $v !== null);
            }
        }

        // Password is never exported
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function import(
        array $data,
        EntityManagerInterface $em,
        ReferenceResolver $resolver,
    ): object {
        $username = $data['username'];

        $user = $em->getRepository($this->userClass)->findOneBy(['username' => $username]);

        if ($user === null) {
            $user = new ($this->userClass)();
            $user->setUsername($username);
        }

        if (isset($data['email']) && method_exists($user, 'setEmail')) {
            $user->setEmail($data['email']);
        }

        if (isset($data['roles']) && method_exists($user, 'setRoles')) {
            $user->setRoles($data['roles']);
        }

        if (isset($data['locale']) && method_exists($user, 'setLocale')) {
            $user->setLocale($data['locale']);
        }

        if (isset($data['avatar']) && method_exists($user, 'setAvatar')) {
            $user->setAvatar($data['avatar']);
        }

        // Password is skipped — manual reset required

        $em->persist($user);
        $resolver->register($this->userClass, $username, $user, 'username');

        return $user;
    }
}
