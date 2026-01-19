import { dispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';

export const sanitizeIdentifier = (value = '') =>
	value.toString().trim().toLowerCase().replace(/[^a-z0-9_-]/g, '');

export const flattenBlocks = (blocks = []) =>
	blocks.reduce((accumulator, block) => {
		if (!block) {
			return accumulator;
		}

		accumulator.push(block);

		if (Array.isArray(block.innerBlocks) && block.innerBlocks.length) {
			accumulator.push(...flattenBlocks(block.innerBlocks));
		}

		return accumulator;
	}, []);

const getPostSavingDispatcher = () => {
	const editorDispatcher = dispatch('core/editor');
	if (editorDispatcher?.lockPostSaving) {
		return editorDispatcher;
	}

	const siteDispatcher = dispatch('core/edit-site');
	if (siteDispatcher?.lockPostSaving) {
		return siteDispatcher;
	}

	return null;
};

export const usePostSavingLock = (shouldLock, lockName, message) => {
	useEffect(() => {
		const dispatcher = getPostSavingDispatcher();
		if (!dispatcher) {
			return undefined;
		}

		if (shouldLock) {
			dispatcher.lockPostSaving(lockName, { message });
		} else {
			dispatcher.unlockPostSaving(lockName);
		}

		return () => dispatcher.unlockPostSaving(lockName);
	}, [shouldLock, lockName, message]);
};
