const config = window.corinthianTable;

export const aiEnabled = () =>
	Boolean( config?.ai?.available && config?.ai?.canUse );
